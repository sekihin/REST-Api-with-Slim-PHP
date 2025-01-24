# REST-Api-with-Slim-PHP
A RESTful API built with Slim PHP framework.

## Project Overview
This project aims to create a robust and scalable RESTful API using the Slim PHP framework. It provides a simple yet powerful way to handle HTTP requests and responses, making it ideal for building modern web applications and services.

## Features
 - ""Slim PHP Framework"": Utilizes the lightweight Slim PHP framework for efficient routing and middleware management.
 - ""RESTful Architecture"": Follows REST principles to ensure a scalable and maintainable API design.
 - ""Database Integration"": Supports integration with various databases (e.g., MySQL, PostgreSQL) for data storage and retrieval.
 - ""Middleware Support"": Implements middleware for authentication, validation, and error handling.
 - ""JSON Response"": Returns data in JSON format for easy consumption by front-end applications.
 - ""Unit Testing"": Includes unit tests to ensure the reliability and functionality of the API.

## Getting Started
### Prerequisites
 - PHP 7.4 or higher
 - Composer
 - A web server (e.g., Apache, Nginx)
 - A database server (e.g., MySQL, PostgreSQL)

### Installation
1. Clone the repository:
```bash
git clone https://github.com/your-username/REST-Api-with-Slim-PHP.git
```

2. Navigate to the project directory:
```bash
cd REST-Api-with-Slim-PHP
```

3. Install dependencies using Composer:
```bash
composer install
```

4. Configure your database settings in the .env file:
```env
DB_HOST=localhost
DB_NAME=your_database_name
DB_USER=your_database_user
DB_PASS=your_database_password
```

5. Run database migrations (if applicable):
```bash
php bin/migrate
```

6. Start the development server:
```bash
php -S localhost:8080 -t public
```

### API Endpoints
EndpointMethodDescription

| Endpoint        | Method | Description                       |
|-----------------|--------|-----------------------------------|
| /users          | GET    | Retrieve a list of users          |
| /users/{id}     | GET    | Retrieve a specific user by ID    |
| /users          | POST   | Create a new user                 |
| /users/{id}     | PUT    | Update an existing user           |
| /users/{id}     | DELETE | Delete a user                     |

## Example Requests
### Create a User
```bash
curl -X POST http://localhost:8080/users \
  -H "Content-Type: application/json" \
  -d '{"name": "John Doe", "email": "john@example.com"}'
```

### Retrieve a User
```bash
curl -X GET http://localhost:8080/users/1
```

## Contributing
Contributions are welcome! Please follow these guidelines:
1. Fork the repository and create your branch from main.
2. Submit a pull request with a clear description of your changes.

## License
This project is licensed under the MIT License.
Feel free to customize this README to better suit your project's needs.