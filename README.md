# Epayco SOAP API Test

Proyecto de una API SOAP para una billetera digital simple, desarrollada con Laravel.

## Prerrequisitos

Asegúrate de tener instalados los siguientes programas en tu sistema:

-   [Git](https://git-scm.com/)
-   [Docker](https://www.docker.com/get-started)
-   [Docker Compose](https://docs.docker.com/compose/install/)

## Puesta en Marcha

1.  **Clonar el repositorio:**

    ```bash
    git clone https://github.com/alexisdjgoyo/epayco-test-api-soap.git
    cd epayco-test-api-soap
    ```

2.  **Crear el archivo de entorno:**

    Copia el archivo de ejemplo `.env.example` a un nuevo archivo llamado `.env`.

    ```bash
    cp .env.example .env
    ```

3.  **Verificar puertos:**

    Asegúrate de no tener ningún otro servicio utilizando los puertos `80` (para el servidor web) y `3306` (para la base de datos).

4.  **Levantar los contenedores con Docker Compose:**

    Este comando construirá las imágenes y levantará los servicios de la aplicación (servidor web Nginx, PHP-FPM y base de datos MySQL).

    ```bash
    docker-compose up -d --build
    ```

    > **Nota:** La primera vez que ejecutes este comando, puede tardar varios minutos mientras Docker descarga las imágenes base y construye los contenedores.

5.  **Instalar dependencias y configurar la aplicación:**

    Una vez que los contenedores estén en ejecución, accede al contenedor de la aplicación y ejecuta los siguientes comandos para instalar las dependencias de Composer y NPM, generar la clave de la aplicación y ejecutar las migraciones de la base de datos.

    ```bash
    docker-compose exec app composer install
    docker-compose exec app npm install
    docker-compose exec app php artisan key:generate
    docker-compose exec app php artisan migrate
    ```

La aplicación estará disponible en `http://localhost`.

## Colección de Postman

En la carpeta `docs` encontrarás una colección de Postman (`epayco-soap-api.postman_collection.json`) que puedes importar para probar los diferentes endpoints de la API SOAP.

## Ejecución de Pruebas

Para ejecutar las pruebas automatizadas, puedes usar el siguiente comando. Esto ejecutará todas las pruebas unitarias y de características definidas en el proyecto, incluyendo las pruebas específicas del archivo `SoapApiTest.php`.

```bash
docker-compose exec app php artisan test
```

Si deseas ejecutar únicamente las pruebas contenidas en `SoapApiTest.php`, puedes hacerlo con el siguiente comando:

```bash
docker-compose exec app php artisan test tests/Feature/SoapApiTest.php
```
