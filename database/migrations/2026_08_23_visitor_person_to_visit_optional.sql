-- The New Visit Request form no longer collects this legacy field. Preserve
-- historical values while allowing new visitor records to omit it.
ALTER TABLE team8_visitors
    MODIFY person_to_visit VARCHAR(150) NULL;
