# Akeneo Extras

Internal bundle for Akeneo with utility commands

# Provides extra commands for
- Deleting attributes (capable of deleting also identifiers)
- Deleting attribute families
- Deleting attribute family variants
- Deleting products
- Deleting product models
- Prune unused media files from the storage

Akeneo blacklists deleted attributes until they are cleaned from products by job runners.
This bundle does not clean the table for it as it is quite unsafe to do so. Therefore you should either run the runners
or delete contents of the table 'pim_catalog_attribute_blacklist' but beware that you can corrupt your database doing that.
We strongly advise that you should use job runners for cleaning of the blacklisted attributes.
