# laravel-cache-repository

Repositories are how I prefer to interact with the data layer in an application.
In an MVC configuration, the repositories sit between the models and the controllers.
In general, the repositories are used to create consistency among commonly used
queries in the application.

On top of the repository pattern, I've added my own layer of object caching. The
individual repositories should extend the CacheRepository so that they can get
and retrieve cached objects consistently without repetitive coding... Keeping it
DRY baby.

## CacheRepository

## Individual Repositories
