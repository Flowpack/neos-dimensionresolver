<?php
namespace Flowpack\Neos\DimensionResolver\Http\ContentDimensionDetection;

/*
 * This file is part of the Neos.Neos package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Psr\Http\Message\ServerRequestInterface;

/**
 * Subdomain based dimension preset detector
 */
final class SubdomainDimensionPresetDetector implements ContentDimensionPresetDetectorInterface
{
    /**
     * @var array
     */
    protected $defaultOptions = [];

    /**
     * @param string $dimensionName
     * @param array $presets
     * @param ServerRequestInterface $request
     * @param array|null $overrideOptions
     * @return array|null
     */
    public function detectPreset(string $dimensionName, array $presets, ServerRequestInterface $request, array $overrideOptions = null)
    {
        $host = $request->getUri()->getHost();

        foreach ($presets as $availablePreset) {
            if (empty($availablePreset['resolutionValue'])) {
                // we leave the decision about how to handle empty values to the detection component
                continue;
            }

            $valueLength = mb_strlen($availablePreset['resolutionValue']);
            $value = mb_substr($host, 0, $valueLength);

            if ($value === $availablePreset['resolutionValue']) {
                if (array_key_exists('resolutionHost', $availablePreset)) {
                    $domain = mb_substr($host, $valueLength+1);
                    if ($domain === $availablePreset['resolutionHost']) {
                        return $availablePreset;
                    }
                } else {
                    return $availablePreset;
                }
            }
        }

        return null;
    }
}
