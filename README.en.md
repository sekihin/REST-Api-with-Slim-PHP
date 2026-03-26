# REST-Api-with-Slim-PHP
A RESTful API project built on the Slim PHP framework.

## Project Overview
This project is a modern, scalable RESTful API developed with the Slim PHP 4 framework. It provides a complete API skeleton with core features including database integration, JWT authentication, email sending, logging, and more.

## Core Features
- **Slim PHP Framework**: Lightweight framework with efficient routing and middleware management
- **RESTful Architecture**: Follows REST design principles to ensure API scalability and maintainability
- **Database Integration**: Supports MySQL databases with database logging middleware
- **JWT Authentication**: Built-in JWT authentication mechanism supporting token generation and verification
- **Email Sending**: Provides email delivery functionality
- **CORS Support**: Cross-origin resource sharing enabled
- **Custom Responses**: Flexible response handling and JSON encoding options
- **Logging**: Database log handler
- **Unit Testing**: Complete PHPUnit test cases

## Software Architecture
```
REST-Api-with-Slim-PHP/
├── public/                 # Web entry directory
│   ├── index.php           # Entry file
│   └── .htaccess           # Apache rewrite rules
├── src/
│   ├── App/                # Core application components
│   │   ├── App.php         # Main application class
│   │   ├── Container.php   # Dependency injection container
│   │   ├── Cors.php        # CORS handling
│   │   ├── Database.php    # Database connection
│   │   ├── Routes.php      # Route definitions
│   │   ├── Middlewares.php # Middlewares
│   │   └── ...
│   ├── Controller/         # Controllers
│   │   └── Home.php        # Home controller
│   └── Common/             # Common utilities
│       ├── auth.php        # JWT authentication
│       └── mail.php        # Email sending
├── tests/integration/      # Integration tests
├── db/                     # Database files
│   └── northwind.sql       # Northwind sample database
├── composer.json           # Composer dependency configuration
├── .env.example            # Environment variable example
└── phpunit.xml             # PHPUnit configuration
```

## System Requirements
- PHP 8.0 or higher
- Composer
- Web server (Apache/Nginx)
- MySQL database

## Installation Steps
1. **Clone the repository**
   ```bash
   git clone https://gitee.com/sekihin/REST-Api-with-Slim-PHP.git
   ```

2. **Navigate to the project directory**
   ```bash
   cd REST-Api-with-Slim-PHP
   ```

3. **Install dependencies**
   ```bash
   composer install
   ```

4. **Configure environment variables**
   ```bash
   cp .env.example .env
   ```
   
   Update the database configuration in `.env` according to your environment:
   ```env
   DB_HOST=localhost
   DB_NAME=your_database_name
   DB_USER=your_database_user
   DB_PASS=your_database_password
   ```

5. **Import the database**
   ```bash
   # Import sample database (optional)
   mysql -u your_database_user -p your_database_name < db/northwind.sql
   ```

6. **Start the development server**
   ```bash
   php -S localhost:8080 -t public
   ```

## API Documentation
### Basic Info
- **API Name**: slim4-api-skeleton
- **API Version**: 1.1.0
- **Base Path**: `/`

### Endpoints
| Endpoint     | Method | Description          |
|--------------|--------|----------------------|
| `/`          | GET    | API help information |
| `/status`    | GET    | Get API status       |
| `/users`     | GET    | Get user list        |
| `/users/{id}`| GET    | Get specified user   |
| `/users`     | POST   | Create new user      |
| `/users/{id}`| PUT    | Update user info     |
| `/users/{id}`| DELETE | Delete user          |

## Usage Examples
### Create User
```bash
curl -X POST http://localhost:8080/users \
  -H "Content-Type: application/json" \
  -d '{"name": "Zhang San", "email": "zhangsan@example.com"}'
```

### Get User
```bash
curl -X GET http://localhost:8080/users/1
```

### Get API Status
```bash
curl -X GET http://localhost:8080/status
```

## Testing
The project includes complete PHPUnit test cases. Run tests with:
```bash
./vendor/bin/phpunit
```

## Contribution Guidelines
Contributions are welcome! Please follow these steps:
1. Fork this repository
2. Create your feature branch (`git checkout -b feature/AmazingFeature`)
3. Commit your changes (`git commit -m 'Add some AmazingFeature'`)
4. Push to the branch (`git push origin feature/AmazingFeature`)
5. Open a Pull Request

## License
This project is licensed under the MIT License.

## Acknowledgments
- [Slim PHP Framework](https://www.slimframework.com/)
- [Gitee](https://gitee.com/) - Code hosting platform