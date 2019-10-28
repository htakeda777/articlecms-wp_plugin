 rm -f ../articlecms-wp_plugin.zip
 zip -r ../articlecms-wp_plugin.zip . -x *.git*
 scp -i ~/.ssh/Keys/fujisan/id_kamiya_2015 ../articlecms-wp_plugin.zip bitnami@articles.magaport.co.jp:
