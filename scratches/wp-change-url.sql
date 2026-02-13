/* LOCAL TO PROD */
UPDATE onis_options SET option_value = replace(option_value, 'lkproduction.loc', 'lkproduction.cz')
WHERE option_name = 'home' OR option_name = 'siteurl';
UPDATE onis_posts SET guid = replace(guid, 'lkproduction.loc', 'lkproduction.cz');
UPDATE onis_posts SET post_content = replace(post_content, 'lkproduction.loc', 'lkproduction.cz');
UPDATE onis_postmeta SET meta_value = replace(meta_value, 'lkproduction.loc', 'lkproduction.cz');

/* PROD TO LOCAL */
UPDATE onis_options SET option_value = replace(option_value, 'lkproduction.cz', 'lkproduction.loc')
WHERE option_name = 'home' OR option_name = 'siteurl';
UPDATE onis_posts SET guid = replace(guid, 'lkproduction.cz', 'lkproduction.loc');
UPDATE onis_posts SET post_content = replace(post_content, 'lkproduction.cz', 'lkproduction.loc');
UPDATE onis_postmeta SET meta_value = replace(meta_value, 'lkproduction.cz', 'lkproduction.loc');

/* DISABLE ALL PLUGINS */
UPDATE onis_options
SET option_value = 'a:0:{}'
WHERE option_name = 'active_plugins';
