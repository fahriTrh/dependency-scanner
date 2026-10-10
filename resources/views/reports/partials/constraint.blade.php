{{ $package['constraint'] }}@if (! empty($package['installed_version']) && $package['installed_version'] !== $package['constraint'])<br>&rarr; {{ $package['installed_version'] }}@endif
