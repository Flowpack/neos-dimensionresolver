# Flowpack Neos Content Dimension Resolver

## Introduction

For a general overview over content dimension, please refer to the respective sections in the Neos manual.

This package enhances the default capabilities of detecting and linking to dimension presets by providing both new
features and extension points.

### Installation

```bash
composer require flowpack/neos-dimensionresolver
```

### Dimension Configuration

The available dimensions and presets can be configured via settings:

```yaml
Neos:
  ContentRepository:
    contentDimensions:

      # Content dimension "language" serves for translation of content into different languages. Its value specifies
      # the language or language variant by means of a locale.
      'language':
        # The default dimension that is applied when creating nodes without specifying a dimension
        default: 'mul_ZZ'
        # The default preset to use if no URI segment was given when resolving languages in the router
        defaultPreset: 'all'
        label: 'Language'
        icon: 'icon-language'
        presets:
          'all':
            label: 'All languages'
            values: ['mul_ZZ']
            resolutionValue: 'all'
          # Example for additional languages:

          'en_GB':
            label: 'English (Great Britain)'
            values: ['en_GB', 'en_ZZ', 'mul_ZZ']
            resolutionValue: 'gb'
          'de':
            label: 'German (Germany)'
            values: ['de_DE', 'de_ZZ', 'mul_ZZ']
            resolutionValue: 'de'
```

**Note:**
The `uriSegment` configuration option provided by default via Neos is still supported but disencouraged.

### Preset resolution

Using this package, content dimension presets can be resolved in different ways additional to the "classic" way of using an URI path segment.
Thus further configuration and implementation options have been added.

The dimension resolver comes with three basic `resolution modes` which can be combined arbitrarily and configured individually.

### URI path segment based resolution

The default resolution mode is `uriPathSegment`. As by default in previous versions, it operates on an additional path segment,
e.g. `https://domain.tld/{language}_{market}/home.html`. These are the configuration options available:

```yaml
Neos:
  ContentRepository:
    contentDimensions:
      'market':
        resolution:
          mode: 'uriPathSegment'
          options:
            # The offset defines the dimension's position in the path segment. Offset 1 means this is the second part.
            # This allows for market being the second uriPath part although it's the primary dimension.
            offset: 1
      'language':
        resolution:
          mode: 'uriPathSegment'
          options:
            # Offset 0 means this is the first part.
            offset: 0
Flowpack:
  Neos:
    DimensionResolver:
      contentDimensions:
       resolution:
         # Delimiter to separate values if multiple dimension are present
         uriPathSegmentDelimiter: '-'
```

With the given configuration, URIs will be resolved like `domain.tld/{language}-{market}/home.html`

**Note:**
An arbitrary number of dimensions can be resolved via uriPathSegment.
The other way around, as long as no content dimensions resolved via uriPathSegment are defined, URIs will not contain any prefix.

The default preset can have an empty `resolutionValue` value. The following example will lead to URLs that do not contain
`en` if the `en_US` preset is active, but will show the `resolutionValue` for other languages that are defined as well:

```yaml
Neos:
  ContentRepository:
    contentDimensions:

      'language':
        label: 'Language'
        icon: 'icon-language'
        default: 'en_US'
        defaultPreset: 'en_US'
        resolution:
          mode: 'uriPathSegment'
        presets:
          'en_US':
            label: 'English (US)'
            values: ['en_US']
            resolutionValue: ''
```

The only limitation is that all resolution values must be unique across all dimensions that are resolved via uriPathSegment.
If you need non-unique resolution values, you can switch support for non-empty dimensions off:

```yaml
Neos:
  Neos:
    routing:
      supportEmptySegmentForDimensions: false
```

### Subdomain based resolution

Another resolution mode is ``subdomain``. This mode extracts information from the first part of the host and adds it respectively
when generating URIs.

```yaml
Neos:
  ContentRepository:
    contentDimensions:
      'language':
        default: 'en'
        defaultPreset: 'en'
        resolution:
          mode: 'subdomain'
          options:
            # true means that if no preset can be detected, the default one will be used.
            # Also when rendering new links, no subdomain will be added for the default preset
            allowEmptyValue: true
        presets:
          'en_GB':
            label: 'English'
            values: ['en']
            resolutionValue: 'en'
          'de':
            label: 'German (Germany)'
            values: ['de_DE']
            resolutionValue: 'de'
```

With the given configuration, URIs will be resolved like `{language}.domain.tld/home.html`

**Note:**
Only one dimension can be resolved via subdomain.

### Top level domain based resolution

The final resolution mode is `topLevelDomain`. This modes extracts information from the last part of the host and adds it respectively
when generating URIs.

```yaml
Neos:
  ContentRepository:
    contentDimensions:
      'market':
        default: 'eu'
        defaultPreset: 'eu'
        resolution:
          mode: 'topLevelDomain'
        presets:
          'EU':
            label: 'European Union'
            values: ['EU']
            resolutionValue: 'eu'
          'GB':
            label: 'Great Britain'
            values: ['GB']
            resolutionValue: 'co.uk'
          'DE':
            label: 'Germany'
            values: ['DE', 'EU']
            resolutionValue: 'de'
```

With the given configuration, URIs will be resolved like ``domain.{market}/home.html``

**Note:**
Only one dimension can be resolved via top level domain.

### Custom resolution

There are planned extension points in place to support custom implementations in case the basic ones do not suffice.

#### Defining custom resolution components

Each resolution mode is defined by two components: An implementation of `Neos\Neos\Http\ContentDimensionDetection\ContentDimensionPresetDetectorInterface`
to extract the preset from an HTTP request and an implementation of `Neos\Neos\Http\ContentDimensionLinking\ContentDimensionPresetLinkProcessorInterface`
for post processing links matching the given dimension presets.

These can be implemented and configured individually per dimension:

```yaml
Neos:
  ContentRepository:
    contentDimensions:
      weather:
        detectionComponent:
          implementationClassName: 'My\Package\Http\ContentDimensionDetection\WeatherDimensionPresetDetector'
        linkProcessorComponent:
          implementationClassName: 'My\Package\Http\ContentDimensionLinking\WeatherDimensionPresetLinkProcessor'
```

If your custom preset resolution components do not affect the URI, you can use the ``Flowpack\Neos\DimensionResolver\Http\ContentDimensionLinking\NullDimensionPresetLinkProcessor``
implementation as the link processor.

**Note:**

If you want to replace implementations of one of the basic resolution modes, you can do it this way, too.

#### Completely replacing resolution behaviour

The described configuration and extension points assume that all dimension presets can be resolved independently.
There may be more complex situations though, where the resolution of one dimension depends on the result of the resolution of another.
As an example, think of a subdomain (language) and top level domain (market) based scenario where you want to support ``domain.fr``,
`domain.de`, `de.domain.ch`, `fr.domain.ch` and `it.domain.ch`. Although you can define the subdomain as optional,
the default language depends on the market: `domain.de` should be resolved to default language `de` and `domain.fr`
should be resolved to default language `fr`.
Those complex scenarios are better served using individual implementations than complex configuration efforts.

To enable developers to deal with this in a nice way, there are predefined ways to deal with both detection and link processing.

Detection is done via an HTTP middleware that can be replaced via configuration:

```yaml
Neos:
  Flow:
    http:
      middlewares:
        detectContentSubgraph:
          middleware: Flowpack\Neos\DimensionResolver\Http\DetectContentSubgraphMiddleware
```

Link processing is done by the `Flowpack\Neos\DimensionResolver\Http\ContentSubgraphUriProcessorInterface`. To introduce your custom behaviour,
implement the interface and declare it in `Objects.yaml` as usual in Flow.

**Note:**
Please refer to the default implementations for further hints and ideas on how to implement resolution.
