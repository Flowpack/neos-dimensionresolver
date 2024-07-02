<?php

namespace Flowpack\Neos\DimensionResolver\Routing;

/*
 * This file is part of the Flowpack.Neos.DimensionResolver package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Flowpack\Neos\DimensionResolver\Http;
use GuzzleHttp\Psr7\Uri;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Utility\NodePaths;
use Neos\ContentRepository\Exception\NodeException;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Log\Utility\LogEnvironment;
use Neos\Flow\Mvc\Routing\Dto\MatchResult;
use Neos\Flow\Mvc\Routing\Dto\ResolveResult;
use Neos\Flow\Mvc\Routing\Dto\RouteTags;
use Neos\Flow\Mvc\Routing\Dto\UriConstraints;
use Neos\Flow\Mvc\Routing\DynamicRoutePart;
use Neos\Flow\Persistence\Exception\IllegalObjectTypeException;
use Neos\Flow\Security\Context;
use Neos\Neos\Domain\Model\Site;
use Neos\Neos\Domain\Repository\DomainRepository;
use Neos\Neos\Domain\Repository\SiteRepository;
use Neos\Neos\Domain\Service\ContentContext;
use Neos\Neos\Domain\Service\ContentContextFactory;
use Neos\Neos\Domain\Service\ContentDimensionPresetSourceInterface;
use Neos\Neos\Domain\Service\NodeShortcutResolver;
use Neos\Neos\Domain\Service\SiteService;
use Neos\Neos\Exception as NeosException;
use Neos\Neos\Routing\Exception;
use Neos\Neos\Routing\Exception\InvalidRequestPathException;
use Neos\Neos\Routing\Exception\InvalidShortcutException;
use Neos\Neos\Routing\Exception\MissingNodePropertyException;
use Neos\Neos\Routing\Exception\NoHomepageException;
use Neos\Neos\Routing\Exception\NoSiteException;
use Neos\Neos\Routing\Exception\NoSuchNodeException;
use Neos\Neos\Routing\Exception\NoWorkspaceException;
use Neos\Neos\Routing\Exception\NoSiteNodeException;
use Neos\Neos\Routing\FrontendNodeRoutePartHandlerInterface;
use Psr\Http\Message\UriInterface;
use Psr\Log\LoggerInterface;

/**
 * A route part handler for finding nodes specifically in the website's frontend.
 */
class FrontendNodeRoutePartHandler extends DynamicRoutePart implements FrontendNodeRoutePartHandlerInterface
{
    /**
     * @Flow\Inject(name="Neos.Flow:SystemLogger")
     * @var LoggerInterface
     */
    protected $systemLogger;

    /**
     * @Flow\Inject
     * @var ContentContextFactory
     */
    protected $contextFactory;

    /**
     * @Flow\Inject
     * @var Context
     */
    protected $securityContext;

    /**
     * @Flow\Inject
     * @var DomainRepository
     */
    protected $domainRepository;

    /**
     * @Flow\Inject
     * @var SiteRepository
     */
    protected $siteRepository;

    /**
     * @Flow\Inject
     * @var Http\ContentSubgraphUriProcessorInterface
     */
    protected $contentSubgraphUriProcessor;

    /**
     * @Flow\Inject
     * @var ContentDimensionPresetSourceInterface
     */
    protected $contentDimensionPresetSource;

    /**
     * @Flow\Inject
     * @var NodeShortcutResolver
     */
    protected $nodeShortcutResolver;

    /**
     * @var Site[] indexed by the corresponding host name
     */
    protected $siteByHostRuntimeCache = [];

    /**
     * Extracts the node path from the request path.
     *
     * @param string $requestPath The request path to be matched
     * @return string value to match, or an empty string if $requestPath is empty or split string was not found
     */
    protected function findValueToMatch($requestPath)
    {
        if ($this->splitString !== '') {
            $splitStringPosition = strpos($requestPath, $this->splitString);
            if ($splitStringPosition !== false) {
                return substr($requestPath, 0, $splitStringPosition);
            }
        }

        return $requestPath;
    }

    /**
     * Matches a frontend URI pointing to a node (for example a page).
     *
     * This function tries to find a matching node by the given request path. If one was found, its
     * absolute context node path is set in $this->value and true is returned.
     *
     * Note that this matcher does not check if access to the resolved workspace or node is allowed because at the point
     * in time the route part handler is invoked, the security framework is not yet fully initialized.
     *
     * @param string $requestPath The request path (without leading "/", relative to the current Site Node)
     * @return bool|MatchResult An instance of MatchResult if the route matches the $requestPath, otherwise FALSE. @see DynamicRoutePart::matchValue()
     * @throws \Exception
     * @throws NoHomepageException if no node could be found on the homepage (empty $requestPath)
     */
    protected function matchValue($requestPath)
    {
        try {
            /** @var NodeInterface $node */
            $node = null;

            // Build context explicitly without authorization checks because the security context isn't available yet
            // anyway and any Entity Privilege targeted on Workspace would fail at this point:
            $this->securityContext->withoutAuthorizationChecks(function () use (&$node, $requestPath) {
                $node = $this->convertRequestPathToNode($requestPath);
            });
        } catch (Exception $exception) {
            $this->systemLogger->debug('FrontendNodeRoutePartHandler matchValue(): ' . $exception->getMessage(), LogEnvironment::fromMethodName(__METHOD__));
            if ($requestPath === '') {
                throw new NoHomepageException('Homepage could not be loaded. Probably you haven\'t imported a site yet', 1719778805, $exception);
            }

            return false;
        }
        if (!$this->nodeTypeIsAllowed($node)) {
            return false;
        }
        if ($this->onlyMatchSiteNodes() && $node !== $node->getContext()->getCurrentSiteNode()) {
            return false;
        }

        $contentContext = $this->buildContentContextFromParameters();
        $tagArray = [$contentContext->getWorkspace()->getName(), $node->getIdentifier()];
        $parent = $node->getParent();
        while ($parent) {
            $tagArray[] = $parent->getIdentifier();
            $parent = $parent->getParent();
        }

        return new MatchResult($node->getContextPath(), RouteTags::createFromArray($tagArray));
    }

    /**
     * Returns the initialized node that is referenced by $requestPath, based on the node's
     * "uriPathSegment" property.
     *
     * @param string $requestPath The request path, for example /the/node/path@some-workspace
     * @return NodeInterface
     * @throws NoSiteException
     * @throws NoSiteNodeException
     * @throws NoSuchNodeException
     * @throws NoWorkspaceException
     * @throws NodeException
     */
    protected function convertRequestPathToNode($requestPath)
    {
        $contentContext = $this->buildContentContextFromParameters();
        $requestPathWithoutContext = $this->removeContextFromPath($requestPath);

        $workspace = $contentContext->getWorkspace();
        if ($workspace === null) {
            throw new NoWorkspaceException(sprintf('No workspace found for request path "%s"', $requestPath), 1719778821);
        }

        $site = $contentContext->getCurrentSite();
        if ($site === null) {
            throw new NoSiteException(sprintf('No site found for request path "%s"', $requestPath), 1719778836);
        }

        $siteNode = $contentContext->getCurrentSiteNode();
        if ($siteNode === null) {
            $currentDomain = $contentContext->getCurrentDomain() ? 'Domain with hostname "' . $contentContext->getCurrentDomain()->getHostname() . '" matched.' : 'No specific domain matched.';
            throw new NoSiteNodeException(sprintf('No site node found for request path "%s". %s', $requestPath, $currentDomain), 1719778849);
        }

        if ($requestPathWithoutContext === '') {
            $node = $siteNode;
        } else {
            $requestPathWithoutContext = $this->truncateUriPathSuffix((string)$requestPathWithoutContext);
            $relativeNodePath = $this->getRelativeNodePathByUriPathSegmentProperties($siteNode, $requestPathWithoutContext);
            $node = ($relativeNodePath !== false) ? $siteNode->getNode($relativeNodePath) : null;
        }

        if (!$node instanceof NodeInterface) {
            throw new NoSuchNodeException(sprintf('No node found on request path "%s"', $requestPath), 1719778866);
        }

        return $node;
    }

    /**
     * Checks, whether given value is a Node object and if so, sets $this->value to the respective node path.
     *
     * In order to render a suitable frontend URI, this function strips off the path to the site node and only keeps
     * the actual node path relative to that site node. In practice this function would set $this->value as follows:
     *
     * absolute node path: /sites/neostypo3org/homepage/about
     * $this->value:       homepage/about
     *
     * absolute node path: /sites/neostypo3org/homepage/about@user-admin
     * $this->value:       homepage/about@user-admin
     *
     * @param NodeInterface|string|string[] $node Either a Node object or an absolute context node path (potentially wrapped in an array as ['__contextNodePath' => '<value>'])
     * @return bool|ResolveResult An instance of ResolveResult if the route could resolve the $node, otherwise FALSE. @see DynamicRoutePart::resolveValue()
     * @throws MissingNodePropertyException | NeosException | IllegalObjectTypeException | NodeException
     * @see NodeIdentityConverterAspect
     */
    protected function resolveValue($node)
    {
        if (is_array($node) && isset($node['__contextNodePath'])) {
            $node = $node['__contextNodePath'];
        }
        if (!$node instanceof NodeInterface && !is_string($node)) {
            return false;
        }

        if (is_string($node)) {
            $nodeContextPath = $node;
            $contentContext = $this->buildContextFromPath($nodeContextPath, true);
            if ($contentContext->getWorkspace() === null) {
                return false;
            }
            $nodePath = $this->removeContextFromPath($nodeContextPath);
            $node = $contentContext->getNode($nodePath);

            if ($node === null) {
                return false;
            }
        } else {
            $contentContext = $node->getContext();
        }

        if (!$this->nodeTypeIsAllowed($node)) {
            return false;
        }
        $siteNode = $contentContext->getCurrentSiteNode();
        if ($this->onlyMatchSiteNodes() && $node !== $siteNode) {
            return false;
        }

        try {
            $nodeOrUri = $this->resolveShortcutNode($node);
        } catch (InvalidShortcutException $exception) {
            $this->systemLogger->debug('FrontendNodeRoutePartHandler resolveValue(): ' . $exception->getMessage(), LogEnvironment::fromMethodName(__METHOD__));
            return false;
        }
        if ($nodeOrUri instanceof UriInterface) {
            return new ResolveResult('', UriConstraints::fromUri($nodeOrUri), null);
        }

        $uriConstraints = $this->contentSubgraphUriProcessor->resolveDimensionUriConstraints($nodeOrUri);
        $uriPath = $this->resolveRoutePathForNode($nodeOrUri);

        if (!empty($this->options['uriPathSuffix']) && $node->getParentPath() !== SiteService::SITES_ROOT_PATH) {
            $uriConstraints = $uriConstraints->withPathSuffix($this->options['uriPathSuffix']);
        }

        return new ResolveResult($uriPath, $uriConstraints);
    }

    /**
     * Removes the configured suffix from the given $uriPath
     * If the "uriPathSuffix" option is not set (or set to an empty string) the unaltered $uriPath is returned
     *
     * @param string $uriPath
     * @return false|string|null
     * @throws InvalidRequestPathException
     */
    protected function truncateUriPathSuffix(string $uriPath): false|string|null
    {
        if (empty($this->options['uriPathSuffix'])) {
            return $uriPath;
        }
        $suffixLength = strlen($this->options['uriPathSuffix']);
        if (substr($uriPath, -$suffixLength) !== $this->options['uriPathSuffix']) {
            throw new InvalidRequestPathException(sprintf('The request path "%s" doesn\'t contain the configured uriPathSuffix "%s"', $uriPath, $this->options['uriPathSuffix']), 1719778525);
        }
        return substr($uriPath, 0, -$suffixLength);
    }

    /**
     * @param NodeInterface $node
     * @return NodeInterface|Uri The original, unaltered $node if it's not a shortcut node. Otherwise the nodes shortcut target (a node or an URI for external & asset shortcuts)
     * @throws InvalidShortcutException
     */
    protected function resolveShortcutNode(NodeInterface $node): Uri|NodeInterface
    {
        $resolvedNode = $this->nodeShortcutResolver->resolveShortcutTarget($node);
        if (is_string($resolvedNode)) {
            return new Uri($resolvedNode);
        }
        if (!$resolvedNode instanceof NodeInterface) {
            throw new InvalidShortcutException(sprintf('Could not resolve shortcut target for node "%s"', $node->getPath()), 1719778533);
        }
        return $resolvedNode;
    }

    /**
     * Creates a content context from the given "context path", i.e. a string used for _resolving_ (not matching) a node.
     *
     * @param string $path a path containing the context, such as /sites/examplecom/home@user-johndoe or /assets/pictures/my-picture or /assets/pictures/my-picture@user-john;language=de&country=global
     * @param boolean $convertLiveDimensions Whether to parse dimensions from the context path in a non-live workspace
     * @return ContentContext based on the specified path; only evaluating the context information (i.e. everything after "@")
     */
    protected function buildContextFromPath($path, $convertLiveDimensions)
    {
        $workspaceName = 'live';
        $dimensions = null;

        if ($path !== '' && NodePaths::isContextPath($path)) {
            $nodePathAndContext = NodePaths::explodeContextPath($path);
            $workspaceName = $nodePathAndContext['workspaceName'];
            $dimensions = ($workspaceName !== 'live' || $convertLiveDimensions === true) ? $nodePathAndContext['dimensions'] : null;
        }

        return $this->buildContextFromWorkspaceName($workspaceName, $dimensions);
    }

    /**
     * @param string $workspaceName
     * @param array|null $dimensions
     * @return ContentContext
     */
    protected function buildContextFromWorkspaceName($workspaceName, array $dimensions = null)
    {
        $contextProperties = [
            'workspaceName' => $workspaceName,
            'invisibleContentShown' => true,
            'inaccessibleContentShown' => true
        ];

        if ($dimensions !== null) {
            $contextProperties['dimensions'] = $dimensions;
        }

        /** @var ContentContext $context */
        $context = $this->contextFactory->create($contextProperties);
        return $context;
    }

    /**
     * Sets context properties like "invisibleContentShown" according to the workspace (live or not) and returns a
     * ContentContext object.
     *
     * @param string $workspaceName Name of the workspace to use in the context
     * @param array $dimensionsAndDimensionValues An array of dimension names (index) and their values (array of strings). See also: ContextFactory
     * @return ContentContext
     * @throws NoSiteException
     */
    protected function buildContextFromWorkspaceNameAndDimensions(string $workspaceName, array $dimensionsAndDimensionValues): ContentContext
    {
        $contextProperties = [
            'workspaceName' => $workspaceName,
            'invisibleContentShown' => ($workspaceName !== 'live'),
            'inaccessibleContentShown' => ($workspaceName !== 'live'),
            'dimensions' => $dimensionsAndDimensionValues,
            'currentSite' => $this->getCurrentSite(),
        ];

        /** @var ContentContext $context */
        $context = $this->contextFactory->create($contextProperties);

        return $context;
    }

    /**
     * @return ContentContext
     * @throws NoSiteException
     */
    protected function buildContentContextFromParameters()
    {
        return $this->buildContextFromWorkspaceNameAndDimensions(
            $this->parameters->getValue('workspaceName') ?? 'live',
            $this->parameters->getValue('dimensionValues') ? json_decode($this->parameters->getValue('dimensionValues'), true) : []
        );
    }

    /**
     * @return bool
     */
    protected function wasUriPathSegmentUsedDuringSubgraphDetection(): bool
    {
        return $this->parameters ? ($this->parameters->getValue('uriPathSegmentUsed') ?? false) : false;
    }

    /**
     * @param string $path an absolute or relative node path which possibly contains context information, for example "/sites/somesite/the/node/path@some-workspace"
     * @return string|null the same path without context information
     */
    protected function removeContextFromPath($path)
    {
        if ($this->wasUriPathSegmentUsedDuringSubgraphDetection()) {
            $pivot = mb_strpos($path, '/');
            if (NodePaths::isContextPath($path)) {
                $pivot--;
            }
            $path = $pivot === false ? '' : mb_substr($path, $pivot + 1);
        }

        if ($path === '' || NodePaths::isContextPath($path) === false) {
            return $path;
        }
        try {
            if (str_starts_with($path, '@')) {
                $path = '/' . $path;
            }
            $nodePathAndContext = NodePaths::explodeContextPath($path);
            return $nodePathAndContext['nodePath'] === '/' ? '' : $nodePathAndContext['nodePath'];
        } catch (\InvalidArgumentException $exception) {
        }

        return null;
    }

    /**
     * Whether the current route part should only match/resolve site nodes (e.g. the homepage)
     *
     * @return boolean
     */
    protected function onlyMatchSiteNodes()
    {
        return isset($this->options['onlyMatchSiteNodes']) && $this->options['onlyMatchSiteNodes'] === true;
    }

    /**
     * Whether the given $node is allowed according to the "nodeType" option
     *
     * @param NodeInterface $node
     * @return bool
     */
    protected function nodeTypeIsAllowed(NodeInterface $node)
    {
        $allowedNodeType = !empty($this->options['nodeType']) ? $this->options['nodeType'] : 'Neos.Neos:Document';
        return $node->getNodeType()->isOfType($allowedNodeType);
    }

    /**
     * Resolves the request path, also known as route path, identifying the given node.
     *
     * A path is built, based on the uri path segment properties of the parents of and the given node itself.
     * If content dimensions are configured, the first path segment will the identifiers of the dimension
     * values according to the current context.
     *
     * @param NodeInterface $node The node where the generated path should lead to
     * @return string The relative route path, possibly prefixed with a segment for identifying the current content dimension values
     * @throws MissingNodePropertyException
     */
    protected function resolveRoutePathForNode(NodeInterface $node)
    {
        $workspaceName = $node->getContext()->getWorkspaceName();

        $nodeContextPath = $node->getContextPath();
        $nodeContextPathSuffix = ($workspaceName !== 'live') ? substr($nodeContextPath, strpos($nodeContextPath, '@')) : '';

        $requestPath = $this->getRequestPathByNode($node);

        return trim($requestPath, '/') . $nodeContextPathSuffix;
    }

    /**
     * Builds a node path which matches the given request path.
     *
     * This method traverses the segments of the given request path and tries to find nodes on the current level which
     * have a matching "uriPathSegment" property. If no node could be found which would match the given request path,
     * false is returned.
     *
     * @param NodeInterface $siteNode The site node, used as a starting point while traversing the tree
     * @param string $relativeRequestPath The request path, relative to the site's root path
     * @return false|string
     * @throws NodeException
     */
    protected function getRelativeNodePathByUriPathSegmentProperties(NodeInterface $siteNode, $relativeRequestPath)
    {
        $relativeNodePathSegments = [];
        $node = $siteNode;

        foreach (explode('/', $relativeRequestPath) as $pathSegment) {
            $foundNodeInThisSegment = false;
            foreach ($node->getChildNodes('Neos.Neos:Document') as $node) {
                if ($node->getProperty('uriPathSegment') === $pathSegment) {
                    $relativeNodePathSegments[] = $node->getName();
                    $foundNodeInThisSegment = true;
                    break;
                }
            }
            if (!$foundNodeInThisSegment) {
                return false;
            }
        }

        return implode('/', $relativeNodePathSegments);
    }

    /**
     * Renders a request path based on the "uriPathSegment" properties of the nodes leading to the given node.
     *
     * @param NodeInterface $node The node where the generated path should lead to
     * @return string A relative request path
     * @throws MissingNodePropertyException if the given node doesn't have a "uriPathSegment" property set
     */
    protected function getRequestPathByNode(NodeInterface $node)
    {
        if ($node->getParentPath() === SiteService::SITES_ROOT_PATH) {
            return '';
        }

        // To allow building of paths to non-hidden nodes beneath hidden nodes, we assume
        // the input node is allowed to be seen and we must generate the full path here.
        // To disallow showing a node actually hidden itself has to be ensured in matching
        // a request path, not in building one.
        $contextProperties = $node->getContext()->getProperties();
        $contextAllowingHiddenNodes = $this->contextFactory->create(array_merge($contextProperties, ['invisibleContentShown' => true]));
        $currentNode = $contextAllowingHiddenNodes->getNodeByIdentifier($node->getIdentifier());

        $requestPathSegments = [];
        while ($currentNode instanceof NodeInterface && $currentNode->getParentPath() !== SiteService::SITES_ROOT_PATH) {
            if (!$currentNode->hasProperty('uriPathSegment')) {
                throw new MissingNodePropertyException(sprintf('Missing "uriPathSegment" property for node "%s". Nodes can be migrated with the "flow node:repair" command.', $node->getPath()), 1719774283);
            }

            $pathSegment = $currentNode->getProperty('uriPathSegment');
            $requestPathSegments[] = $pathSegment;
            $currentNode = $currentNode->getParent();
        }

        return implode('/', array_reverse($requestPathSegments));
    }

    /**
     * Determines the currently active site based on the "requestUriHost" parameter (that has to be set via HTTP middleware)
     *
     * @return Site
     * @throws NoSiteException
     */
    protected function getCurrentSite()
    {
        $requestUriHost = $this->parameters->getValue('requestUriHost');
        if (!is_string($requestUriHost)) {
            throw new NoSiteException('Failed to determine current site because the "requestUriHost" Routing parameter is not set', 1719778761);
        }
        if (!array_key_exists($requestUriHost, $this->siteByHostRuntimeCache)) {
            $this->siteByHostRuntimeCache[$requestUriHost] = $this->getSiteByHostName($requestUriHost);
        }
        return $this->siteByHostRuntimeCache[$requestUriHost];
    }

    /**
     * Returns a site matching the given $hostName
     *
     * @param string $hostName
     * @return Site
     * @throws NoSiteException
     */
    protected function getSiteByHostName(string $hostName)
    {
        $domain = $this->domainRepository->findOneByHost($hostName, true);
        if ($domain !== null) {
            return $domain->getSite();
        }
        try {
            $defaultSite = $this->siteRepository->findDefault();
            if ($defaultSite === null) {
                throw new NoSiteException('Failed to determine current site because no default site is configured', 1719778771);
            }
        } catch (NeosException $exception) {
            throw new NoSiteException(sprintf('Failed to determine current site because no domain is specified matching host of "%s" and no default site could be found: %s', $hostName, $exception->getMessage()), 1719778778, $exception);
        }
        return $defaultSite;
    }
}
