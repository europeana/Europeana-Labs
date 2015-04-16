Europeana Labs
==============

 - Version: 1.0
 - Date: 2015-04-16
 - Author: Bob den Otter - bob@twokings.nl

There's a new github Repository here:

 - https://github.com/europeana/Europeana-Labs

Either Dasha or I can give you write-access to this repository. Currently, the repository
is publically readable, just like the "Pro" and "Research" websites.

You can either work in this one, or we'll move the contents of this one into the
repository where you'd like to have it.

Bolt
----

The new site runs on Bolt. A modern, flexible, open source CMS, that's under
active development:

- Website: https://bolt.cm
- Documentation: https://docs.bolt.cm
- Github: https://github.com/bolt/bolt

Bolt uses Twig for templating: http://twig.sensiolabs.org .

In practice, it should never be required to modify any of the core PHP files.
Doing so, would make upgrading much trickier, because modifications will get
overwritten by the update, if you're not careful. If you need custom
functionality, you should make an extension, to enhance the core functionality
of Bolt.

There are a ton of extensions on https://extensions.bolt.cm . What you need might
be there already, or at least there will be an extension that can serve as a
guideline for how to do stuff. If there's anything that you're not sure about if
it's included in 'core', or if it requires an extension, feel free to check it
with me. I'll gladly advise on these things.

Hosting
-------

The site will be hosted on the Engine Yard platform, like the other europeana
websites. I've set up a 'labsbeta' area already. Eventually we'll have two
instances for labs.europeana.eu and labs-beta.europeana.eu. The current
'staging' environment will become 'labs-beta'. For now it can be reached as

 - http://ec2-54-194-176-140.eu-west-1.compute.amazonaws.com/

Login and deployment though engineyard. Dasha can arrange an account there, if you don't have one yet.

 - https://login.engineyard.com/login

To log on to the backend, go to http://ec2-54-194-176-140.eu-west-1.compute.amazonaws.com/bolt/

I've added a username, and you can add more yourself:

 - username: labs
 - password: lan$tv456bw

Setting up a local Development version
--------------------------------------

To check out a local copy of the website, follow these steps:

```
git clone git@github.com:europeana/Europeana-Labs.git
cd Europeana-Labs
mkdir app/cache
mkdir app/database
mkdir thumbs
chmod -R 777 files/ app/database/ app/cache/ app/config/
chmod -R 777 theme/ extensions/ thumbs/
```

If there's already cached files in the `app/cache` folder, you'll get some notices. You can safely ignore these.

The main configuration of Bolt is set in `app/config/config.yml`. But, because this file
is included in the git repository, this is not a good place to store the database
credentials. Copy the file `app/config/config_local.yml.dist` to
`app/config/config_local.yml` and use this file to _override_ any settings you want to
override. This is a good place to set the database credentials.

Note: This file should _NOT_ be checked in to git.

Other than this file, all files are in git, including the `vendor` and `composer` files.
This is because of the deployment process that Engine yard uses.

Other than that, you should've gotten a zipped `.sql` file that can serve as a skeleton
for the structure of the new site.

Configuration
-------------

The site currently is a copy of research.europeana.eu, including the content types that
are used in that sites. You might have need for different content-types, or you might want
to trim down the current contenttypes.

This can be done in the file `app/config/contenttypes.yml`.

The theme of the site is defined in `theme/europeana-labs`. This is currently also a copy
of the 'research' site, together with a bunch of files that are still in there from the
"pro" website.

Depending on how you're planning to do the modifications to the theming, I think this
would be a good opportunity to clean up the templates. Once you've settled on a way to work with
this, I'll help sort it out. I see there's some activity in Patternlab on a Labs
styleguide, so i assume this will be used for the labs site, right?


