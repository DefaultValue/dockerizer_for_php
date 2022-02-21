Placeholders for `docker-compose.yaml` file, mostly accepted as command parameters or defined in the application recipe file:

- {{main_domain}}
- {{domains}}
- {{environment}}
- {{composer_version}}

Pull templates from the repository. Implement ability do add and remove multiple repositories with templates

`composition:template:list-all` - filter packages by name or supported package? Delete and use meta only!
`composition:template:meta` - filter packages by name or supported package

-----

Custom Dockerfiles: should implement ability to use custom Dockerfiles for compositions, and provide parameters to these
Dockerfiles as we do this for docker-compose.yml and mounted files


--- Notes for documentation ---

1. Runner yaml does not override configuration of additional services.
2. Group name must not match service name in this group. Otherwise, the input may be ambiguous.
3. Passing parameters does not overwrite predefined service parameters.
4. If the file or dir from the docker-compose volumes list is present in the project root and is not present in the dir with composition - volume host OS path is updated to mount such directory (need an example in the documentation)
5. 

Adding parameters and processing parameter value:

- {{composer_version}} - nothing special here
- {{domains|first}} - get the first value from array (space-separated values)
- {{domains|first|replace:.:-}} - get the first value and replace `.` (dot) with `-` (dash)
- {{domains|enclose:'}} - enclose a single value or all array values with quotes
- {{domains|explode:,}} - explode value to array, use ',' (comma) as a separator
- {{domains|implode:,}} - implode array to string, use ',' (comma) as a separator