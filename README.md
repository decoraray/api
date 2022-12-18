composer install

php artisan migrate:fresh --seed

###

Docker

```
docker-compose up
```

###

Docker (without composer)

build image:
docker build -t my_app .

find <imageID>:
docker image ls

then:
docker run -p 8000:8000 -d <imageID>

to know what is the name (or container ID) of our app:
docker ps

then:
docker exec -it <container ID> sh

Inside the container command line, we can start the server using the usual:
php artisan serve
