<?php
namespace Flowpack\Neos\DimensionResolver\Http;

/*
 * This file is part of the Neos.Neos package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\ContentRepository\Domain\Service\ContentDimensionPresetSourceInterface;
use Neos\ContentRepository\Domain\Service\ContextFactoryInterface;
use Neos\ContentRepository\Domain\Utility\NodePaths;
use Neos\Flow\Annotations as Flow;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

use Neos\Flow\Mvc\Routing\Dto\RouteParameters;
use Neos\Flow\Http\ServerRequestAttributes;

use Flowpack\Neos\DimensionResolver\Http\ContentDimensionDetection\DimensionPresetDetectorResolver;

/**
 * The HTTP component for detecting the requested dimension space point
 */
final class DetectContentSubgraphMiddleware implements MiddlewareInterface
{
    /**
     * @Flow\Inject
     * @var ContentDimensionPresetSourceInterface
     */
    protected $dimensionPresetSource;

    /**
     * @Flow\Inject
     * @var ContextFactoryInterface
     */
    protected $contextFactory;

    /**
     * @Flow\Inject
     * @var DimensionPresetDetectorResolver
     */
    protected $contentDimensionPresetDetectorResolver;

    /**
     * @Flow\InjectConfiguration(path="routing.supportEmptySegmentForDimensions", package="Neos.Neos")
     * @var boolean
     */
    protected $allowEmptyPathSegments;

    /**
     * @Flow\InjectConfiguration(path="contentDimensions.resolution.uriPathSegmentDelimiter")
     * @var string
     */
    protected $uriPathSegmentDelimiter;

    /**
     * @param ServerRequestInterface $request
     * @param RequestHandlerInterface $next
     * @throws Exception\InvalidDimensionPresetDetectorException
     * @return ResponseInterface
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $next): ResponseInterface
    {
        $uriPathSegmentUsed = false;
        $dimensionValues = $this->detectDimensionSpacePoint($request, $uriPathSegmentUsed);
        $workspaceName = $this->detectContentStream($request);

        $existingParameters = $request->getAttribute(ServerRequestAttributes::ROUTING_PARAMETERS);
        if ($existingParameters === null) {
            $existingParameters = RouteParameters::createEmpty();
        }

        $parameters = $existingParameters
            ->withParameter('dimensionValues', json_encode($dimensionValues))
            ->withParameter('workspaceName', $workspaceName)
            ->withParameter('uriPathSegmentUsed', $uriPathSegmentUsed);

        $request = $request->withAttribute(ServerRequestAttributes::ROUTING_PARAMETERS, $parameters);
        return $next->handle($request);
    }

    /**
     * @param ServerRequestInterface $request
     * @param bool $uriPathSegmentUsed
     * @return array
     * @throws Exception\InvalidDimensionPresetDetectorException
     */
    protected function detectDimensionSpacePoint(ServerRequestInterface $request, bool &$uriPathSegmentUsed): array
    {
        $coordinates = [];

        $path = $request->getUri()->getPath();

        $isContextPath = NodePaths::isContextPath($path);
        $backendUriDimensionPresetDetector = new ContentDimensionDetection\BackendUriDimensionPresetDetector();
        $presets = $this->dimensionPresetSource->getAllPresets();
        $this->sortPresetsByOffset($presets);
        $uriPathSegmentOffset = 0;
        foreach ($presets as $dimensionName => $presetConfiguration) {
            $detector = $this->contentDimensionPresetDetectorResolver->resolveDimensionPresetDetector($dimensionName, $presetConfiguration);

            $options = $presetConfiguration['resolution']['options'] ?? $this->generateOptionsFromLegacyConfiguration($presetConfiguration, $uriPathSegmentOffset);
            $options['defaultPresetIdentifier'] = $presetConfiguration['defaultPreset'];

            if ($isContextPath) {
                $preset = $backendUriDimensionPresetDetector->detectPreset($dimensionName, $presetConfiguration['presets'], $request);
                if ($preset) {
                    $coordinates[$dimensionName] = $preset['values'];
                    if ($detector instanceof ContentDimensionDetection\UriPathSegmentDimensionPresetDetector) {
                        // we might have to remove the uri path segment anyway
                        $uriPathSegmentPreset = $detector->detectPreset($dimensionName, $presetConfiguration['presets'], $request, $options);
                        if ($uriPathSegmentPreset) {
                            $uriPathSegmentUsed = true;
                        }
                    }
                    continue;
                }
            }

            $resolutionMode = $presetConfiguration['resolution']['mode'] ?? ContentDimensionResolutionMode::RESOLUTION_MODE_URIPATHSEGMENT;
            if ($resolutionMode === ContentDimensionResolutionMode::RESOLUTION_MODE_URIPATHSEGMENT) {
                if (!empty($this->uriPathSegmentDelimiter)) $options['delimiter'] = $this->uriPathSegmentDelimiter;
            }
            $preset = $detector->detectPreset($dimensionName, $presetConfiguration['presets'], $request, $options);
            if ($preset && $resolutionMode === ContentDimensionResolutionMode::RESOLUTION_MODE_URIPATHSEGMENT) {
                $uriPathSegmentUsed = true;
                $uriPathSegmentOffset++;
            }
            if (!$preset && $options && isset($options['allowEmptyValue']) && $options['allowEmptyValue']) {
                if (isset($options['defaultPresetIdentifier']) && $options['defaultPresetIdentifier'] && isset($presetConfiguration['presets'][$options['defaultPresetIdentifier']])) {
                    $preset = $presetConfiguration['presets'][$options['defaultPresetIdentifier']];
                }
            }
            if ($preset) {
                $coordinates[$dimensionName] = $preset['values'];
            }
        }

        return $coordinates;
    }

    /**
     * @param array $presets
     * @return void
     */
    protected function sortPresetsByOffset(array & $presets)
    {
        uasort($presets, function ($presetA, $presetB) use ($presets) {
            if (isset($presetA['resolution']['options']['offset'])
                && isset($presetB['resolution']['options']['offset'])) {
                return $presetA['resolution']['options']['offset'] <=> $presetB['resolution']['options']['offset'];
            }

            return 0;
        });
    }

    /**
     * @todo remove once legacy configuration is removed (probably with 4.0)
     * @param array $presetConfiguration
     * @param int $uriPathSegmentOffset
     * @return array|null
     */
    protected function generateOptionsFromLegacyConfiguration(array $presetConfiguration, int $uriPathSegmentOffset)
    {
        $options = null;

        $resolutionMode = $presetConfiguration['resolution']['mode'] ?? ContentDimensionResolutionMode::RESOLUTION_MODE_URIPATHSEGMENT;
        if ($resolutionMode === ContentDimensionResolutionMode::RESOLUTION_MODE_URIPATHSEGMENT) {
            $options = [];
            if (!isset($options['offset'])) {
                $options['offset'] = $uriPathSegmentOffset;
            }
            if ($this->allowEmptyPathSegments) {
                $options['allowEmptyValue'] = true;
            } else {
                $options['allowEmptyValue'] = false;
            }
        }

        return $options;
    }

    /**
     * @param ServerRequestInterface $request
     * @return string
     */
    protected function detectContentStream(ServerRequestInterface $request): string
    {
        $contentStreamIdentifier = 'live';

        $requestPath = $request->getUri()->getPath();
        $requestPath = mb_substr($requestPath, mb_strrpos($requestPath, '/'));
        if ($requestPath !== '' && NodePaths::isContextPath($requestPath)) {
            try {
                $nodePathAndContext = NodePaths::explodeContextPath($requestPath);
                $contentStreamIdentifier = $nodePathAndContext['workspaceName'];
            } catch (\InvalidArgumentException $exception) {
            }
        }

        return $contentStreamIdentifier;
    }
}
