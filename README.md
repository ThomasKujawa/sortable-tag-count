# Sortable-Tag-Count

## Why?

In one project, we had assigned several hundred keywords. In an SEO audit, we were advised to minimize the number of keywords. Deleting the keywords was of course no problem. Redirecting the now missing archive pages was also straightforward. However, I was now faced with the problem of which of the several blog posts now had none, one or x keywords. I couldn't find a solution or a code snippet anywhere.

## Description

### Features

The plugin adds a new, sortable column to the overview of pages and posts in the backend. The number of assigned keywords is displayed in this column. When the posts or pages are updated, the number is updated if necessary.

### Usage

Activate the plugin. The column is displayed. Click on the column header to change the sorting.

### Playground:

Click [here](https://playground.wordpress.net/?mode=seamless#{%22landingPage%22:%22/wp-admin/edit.php%22,%22preferredVersions%22:{%22php%22:%228.2%22,%22wp%22:%22latest%22},%22features%22:{%22networking%22:true},%22steps%22:[{%22step%22:%22login%22,%22username%22:%22admin%22,%22password%22:%22password%22},{%22step%22:%22installPlugin%22,%22pluginZipFile%22:{%22resource%22:%22wordpress.org/plugins%22,%22slug%22:%22sortable-tag-count%22},%22options%22:{%22activate%22:true}}]}) to start the plugin in playground.

## Frequently Asked Questions

### Does this plugin work with PHP 8?

Yes, it's actively tested and working up to PHP 8.3.

## Check for WordPress Coding Standards

### Initialize

`composer install`

### Run

`vendor/bin/phpcs --extensions=php --ignore=*/assets/*,*/vendor/* --standard=ruleset.xml .`

### Repair

`vendor/bin/phpcbf --extensions=php --ignore=*/assets/*,*/vendor/* --standard=ruleset.xml .`

## Check for WordPress VIP Coding Standards

Hint: this check runs against the VIP-GO-platform which is not our target for this plugin. Many warnings can be ignored.

### Run

`vendor/bin/phpcs --extensions=php --ignore=*/vendor/*,*/node_modules/*,*/block/*,*/svn/*,*/src/* --standard=WordPress-VIP-Go .`