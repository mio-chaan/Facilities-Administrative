-- Concurrency-safe counters for human-readable HR document numbers.
CREATE TABLE IF NOT EXISTS team8_document_number_sequences (
    prefix          VARCHAR(30) NOT NULL,
    sequence_year   SMALLINT UNSIGNED NOT NULL,
    last_number     INT UNSIGNED NOT NULL,
    PRIMARY KEY (prefix, sequence_year)
) ENGINE=InnoDB;