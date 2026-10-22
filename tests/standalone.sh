#!/bin/sh
set -eu
wp plugin deactivate terrible-backups-integrations terrible-mail-integrations
wp plugin deactivate --all
for plugin in terrible-backups terrible-mail terrible-redirects terrible-media terrible-login terrible-metadata terrible-links terrible-revisions; do
    wp plugin activate "$plugin"
    wp eval 'if (TerribleTools\Admin::grouped()) throw new RuntimeException("Single core must not group"); do_action("admin_menu"); echo "Standalone WordPress bootstrap passed.\n";'
    wp plugin deactivate "$plugin"
done
wp plugin activate terrible-backups terrible-mail terrible-backups-integrations terrible-mail-integrations terrible-redirects terrible-media terrible-login terrible-metadata terrible-links terrible-revisions
