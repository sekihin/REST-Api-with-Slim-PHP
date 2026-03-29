# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a PHP REST API project built on Slim Framework 4 with AI agent capabilities. It provides a RESTful API with database integration, JWT authentication, and AI-powered chat/agent functionality.

## Commands

```bash
# Install dependencies
composer install

# Start development server
php -S localhost:8080 -t public public/index.php

# Run tests
composer test
# or
./vendor/bin/phpunit

# Run tests with coverage
composer coverage
# or
./vendor/bin/phpunit --coverage-text --coverage-html coverage

# Generate CRUD endpoints
composer crud
```

## Architecture

The project follows Clean Architecture with these layers:

- **App/** - Core application components (App, Container, Database, Routes, Middlewares, CORS, ErrorHandler)
- **Application/** - Controllers and Actions (API endpoints)
- **Domain/** - Business logic (InventoryService, KnowledgeBaseService, OrderService)
- **Infrastructure/** - External integrations (AI agents, tools, external providers, logging)

## AI Agents

The system includes specialized AI agents in `src/Infrastructure/AI/Agents/`:
- **CheckoutAgent** - Handles checkout processes
- **GeneralChatAgent** - General conversation
- **GetDeliveryAgent** - Delivery inquiry handling
- **RouterAgent** - Routes requests to appropriate agents

AI tools in `src/Infrastructure/AI/Tools/`:
- CheckDeliveryTool, CheckInventoryTool, LookupOrderTool, ProcessOrderTool, RefundOrderTool, SearchFaqTool

## LLM Providers

Supports multiple LLM providers configured via `.env`:
- `LLM_PROVIDER` - Current provider (doubao/deepseek/gemini)
- `EMBEDDING_MODEL` - Embedding model for knowledge bases

## Database

- MySQL database (configured in `.env`)
- Uses PDO for database connections
- Database handler for logging in `src/Infrastructure/Logging/DatabaseHandler.php`

## Key Dependencies

- Slim Framework 4 (routing/middleware)
- PHP-DI (dependency injection)
- Firebase/JWT (authentication)
- Neuron AI (AI agent framework)
- Guzzle (HTTP client)
- Monolog (logging)
