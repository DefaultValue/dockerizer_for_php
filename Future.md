Placeholders for `docker-compose.yaml` file, mostly accepted as command parameters or defined in the application recipe file:

- {{main_domain}}
- {{domains}}
- {{environment}}
- {{composer_version}}

Pull templates from the repository. Implement ability do add and remove multiple repositories with templates

`composition:template:list-all` - filter packages by name or supported package? Delete and use meta only!
`composition:template:meta` - filter packages by name or supported package

-----

`php_7.0_apache:php-apache` - select runner and set service to link other services to

Parameter and options meaning example:
- `{{composer_version}}` - nothing special here
- `{{domains:0}}` - get the first value from array (space-separated values)
- `{{domains| }}` - implode the parameter using ' ' (empty string) as a separator
- `{{domains|,|'}}` - implode the parameter using ' ' (empty string) as a separator, enclose values with single quotes
- `{{domains:1|,|'}`} - not a valid value (ambiguous shortcode)

Custom Dockerfiles: one for production and one for dev tools image!