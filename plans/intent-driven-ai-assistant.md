# Plan: Intent-Driven, Context-Aware AI Assistant for UIS Knowledge Retrieval

> Source PRD: docs/product/prd/v2.0.0.md

## Architectural decisions

Durable decisions that apply across all phases:

- **Routes**: API endpoint `/api/chat` for queries, `/api/index` for document indexing
- **Schema**: Elasticsearch index `rag_documents` with fields: content, embedding (2048-dim), metadata (title, section, document_id, intent_type)
- **Key models**: Document {content, metadata}, IntentType enum (faq, email_template, operation_manual, general)
- **LLM Provider**: Doubao-seed-2-0-mini-260215 via existing DoubaoProvider

---

## Phase 1: Core RAG Pipeline

**User stories**: #1, #2, #5, #9

### What to build

Build the complete query-to-answer flow without actions:

1. **QueryPreprocessor** - Clean and normalize user input (lowercase, trim, handle special characters)
2. **IntentRouter** - Use LLM to classify queries into: faq, email_template, operation_manual, or general
3. **HybridRetriever** - Retrieve top-k documents from Elasticsearch based on intent type
4. **ResponseSynthesizer** - Generate natural language answer using LLM with retrieved context

### Acceptance criteria

- [ ] QueryPreprocessor normalizes input: "  How do I reset password?  " → "how do i reset password"
- [ ] IntentRouter classifies "How do I process a buyout?" as "operation_manual"
- [ ] IntentRouter classifies "What's the error code E001?" as "faq"
- [ ] IntentRouter returns "general" for ambiguous queries (fallback behavior)
- [ ] HybridRetriever returns relevant documents filtered by intent type
- [ ] ResponseSynthesizer generates coherent natural language responses
- [ ] End-to-end query returns answer within 3 seconds

---

## Phase 2: Action Executor

**User stories**: #3, #4

### What to build

Add structured action execution capability:

1. **ActionExecutor** - Execute predefined actions and return structured JSON data
2. **find_installer action** - Query software installers, return download links, version info
3. **get_shipping_date action** - Query buyout software shipping dates

### Acceptance criteria

- [ ] ActionExecutor identifies when query requires action vs RAG retrieval
- [ ] find_installer returns {software_name, version, download_url, file_size} for queries like "Where can I download the UIS client?"
- [ ] get_shipping_date returns {order_id, software_name, shipping_date, tracking_number} for queries like "When will the buyout software ship?"
- [ ] Action results are structured JSON, not natural language
- [ ] Unknown actions gracefully fall back to general search

---

## Phase 3: Document Indexer

**User stories**: #6, #7

### What to build

Build the document ingestion pipeline:

1. **DocumentIndexer** - Index documents into Elasticsearch with semantic chunking
2. **Semantic chunking** - Split documents by meaning boundaries, not arbitrary length
3. **Intent type tagging** - Tag each document with its intent category (faq, email_template, operation_manual, workflow)
4. **Support document types**: Operation Manuals, FAQs, Email Templates, Workflows

### Acceptance criteria

- [ ] DocumentIndexer creates/updates Elasticsearch index with proper mappings
- [ ] Documents are chunked semantically (coherent paragraphs, not arbitrary 512-token chunks)
- [ ] Each chunk includes metadata: title, section, document_id, intent_type
- [ ] Existing KnowledgeBaseService::searchDocuments() works with new index
- [ ] CLI test `php demo_knowledge_base_cli.php "query"` works end-to-end

---

## Phase 4: Integration & Testing

**User stories**: #8, #10, #11

### What to build

Verify the complete system works:

1. **Unit tests** - Test each module in isolation with mocked dependencies
2. **Integration tests** - Verify KnowledgeBaseService, InventoryService, Order work independently
3. **CLI validation** - Run demo_knowledge_base_cli.php to verify search functionality
4. **SLA validation** - Verify response time < 3 seconds, retrieval relevance > 85%

### Acceptance criteria

- [ ] QueryPreprocessor has unit tests covering normalization edge cases
- [ ] IntentRouter has unit tests covering classification accuracy
- [ ] HybridRetriever has unit tests for different intent types
- [ ] ActionExecutor has unit tests for find_installer and get_shipping_date
- [ ] `php demo_knowledge_base_cli.php "troubleshooting error"` returns results
- [ ] Response time from API endpoint < 3 seconds
- [ ] System availability verified at 99.5%
