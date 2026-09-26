-- Phase 9: classify legal-document links without duplicating Document Management files.
-- Apply only to an existing database; fresh installs use database/schema.sql.

ALTER TABLE team8_legal_documents
    ADD COLUMN legal_document_type VARCHAR(100) NOT NULL DEFAULT 'Other' AFTER document_id;