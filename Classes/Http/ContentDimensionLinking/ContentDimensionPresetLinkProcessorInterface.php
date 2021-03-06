<?php
namespace Flowpack\Neos\DimensionResolver\Http\ContentDimensionLinking;

/*
 * This file is part of the Neos.Neos package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\Flow\Mvc\Routing;

/**
 * Interface to detect the current request's dimension preset
 */
interface ContentDimensionPresetLinkProcessorInterface
{
    /**
     * @param Routing\Dto\UriConstraints $uriConstraints
     * @param string $dimensionName
     * @param array $presetConfiguration
     * @param array $preset
     * @param array|null $overrideOptions
     * @return Routing\Dto\UriConstraints
     */
    public function processUriConstraints(
        Routing\Dto\UriConstraints $uriConstraints,
        string $dimensionName,
        array $presetConfiguration,
        array $preset,
        array $overrideOptions = null
    ): Routing\Dto\UriConstraints;
}
