Pull templates from the repository. Implement ability do add and remove multiple repositories with templates

`composition:template:list-all` - filter packages by name or supported package? Delete and use meta only!
`composition:template:meta` - filter packages by name or supported package

-----

@TODO

1. Move templates to the Composer packages. Should we prefix templates by vendor? Yes!
2. Support custom Dockerfiles for services.
3. Add command for get template meta information: name, supported packages, services, variables, etc.
4. Support Magento EE, B2B, Cloud.
5. Add MacOS support.
6. Fix issue displaying full dockerization or Magento setup command.
7. Rename ProcessibleFile to ProcessableFile
8. Get command descriptions and short descriptions to generate documentation to keep this information fully consistent and synchronized with the code.
9. Create command option and argument registry OR extract all of them into the OptionDefinitionInterface/CommandArgumentInterface to guarantee consistent descriptions and documentation.
10. Generate random Magento admin password.