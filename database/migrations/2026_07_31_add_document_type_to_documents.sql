-- Adds document type support for the Document Management module.
-- This migration keeps existing documents intact while introducing a dependent
-- document-type field for new uploads.

ALTER TABLE team8_documents
    ADD COLUMN document_type VARCHAR(150) NULL AFTER category_id;
