# Architecture and coding guidelines #

Follow the below guidelines if you plan to extend or modify this tool.

## Naming ##

### Constants ###

Names of the environment-related constants be prefixed as *ENV_*. Existing constant names will be changed in the future.

Environment variables must be prefixed with `DOCKERIZER_` to distinguish them from other environment variables.
Existing constant names will be changed in the future.

:x: Example old naming:
```php
public const PROJECTS_ROOT_DIR = 'PROJECTS_ROOT_DIR';
```

:heavy_check_mark: Example correct naming:
```php
public const ENV_PROJECTS_ROOT_DIR = 'DOCKERIZER_PROJECTS_ROOT_DIR';
```