# SimpleLib Plugin Upgrader Class
Envato API. Upgrading CodeCanyon plugin from WordPress

All the upgrading data are provided by the server WordPress, i.e. When your blog requests this data from wordpress.org server, it gets all the necessary information. Envato Server also allows you to receive all the necessary information about updating the plugin that is sold on CodeCanyon (plugins on Envato Market), but with another data structure (other than from WordPress). Therefore the task is to:

1. Obtain information of the availability of a new version of the plugin and notify WordPress about the existence of it
2. Obtain information about the plugin for future viewing by the user of the plugin (blog administrator)
3. Provide access to the file of the new version of the plugin on the server Envato to downloads and updates

All three point of plan can be implemented using three WordPress filters, namely `pre_set_site_transient_update_plugins`,  `plugins_api` and `upgrader_package_options`.

See more info on [SimpleLib](http://www.simplelib.com/archives/envato-api-upgrading-plugin-from-wordpress/)...
