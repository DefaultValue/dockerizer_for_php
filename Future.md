Pull templates from the repository. Implement ability do add and remove multiple repositories with templates

`composition:template:list-all` - filter packages by name or supported package? Delete and use meta only!
`composition:template:meta` - filter packages by name or supported package

-----

@TODO

1. Create a documentation website.
2. Move templates to the Composer packages. Should we prefix templates by vendor? Yes!
3. Implement service-level dev tools - in progress.
4. Support custom Dockerfiles for services.
5. Add command for get template meta information: name, supported packages, services, variables, etc.
6. Implement commands to installing Magento modules.
7. Move Traefik to a separate service and initialize it when Ubuntu is installed.
8. Support Magento EE, B2B, Cloud.
9. Add MacOS support.
10. Fix issue displaying full dockerization or Magento setup command.
11. Fix issue with inability to skip using Redis in non-interactive mode.
12. Rename ProcessibleFile to ProcessableFile 