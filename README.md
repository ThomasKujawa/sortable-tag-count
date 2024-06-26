# Sortable-Tag-Count

Stable tag: 1.1.0

Tested up to: 6.5.2

License: GPL v3

Tags: tags, sortable column, backend

Contributors: thomaskujawa, threadi

Donate link: https://www.paypal.me/ThomasKujawa

## Why?

In one project, we had assigned several hundred keywords. In an SEO audit, we were advised to minimize the number of keywords. Deleting the keywords was of course no problem. Redirecting the now missing archive pages was also straightforward. However, I was now faced with the problem of which of the several blog posts now had none, one or x keywords. I couldn't find a solution or a code snippet anywhere.

## Description

### Features

The plugin adds a new, sortable column to the overview of pages and posts in the backend. The number of assigned keywords is displayed in this column. When the posts or pages are updated, the number is updated if necessary.

### Usage

Activate the plugin. The column is displayed. Click on the column header to change the sorting.

### Accessibility Statement

## Screenshots

## Frequently Asked Questions

### Does this plugin work with PHP 8?

Yes, it's actively tested and working up to PHP 8.3.

## Check for WordPress Coding Standards

### Initialize

`composer install`

### Run

`vendor/bin/phpcs --standard=ruleset.xml file`

### Repair

`vendor/bin/phpcbf --standard=ruleset.xml file`