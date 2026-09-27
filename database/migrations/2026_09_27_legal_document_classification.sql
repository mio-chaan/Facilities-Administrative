-- Normalize shared DMS documents linked to Legal cases.
-- Apply after the Legal document category and Legal link tables exist.

UPDATE team8_documents d
JOIN (
    SELECT DISTINCT document_id
    FROM team8_legal_documents
) legal_links ON legal_links.document_id = d.id
JOIN (
    SELECT MIN(id) AS id
    FROM team8_document_categories
    WHERE name = 'Legal'
    HAVING COUNT(*) > 0
) c ON TRUE
SET d.category_id = c.id;

UPDATE team8_documents d
JOIN (
    SELECT
        ld.document_id,
        MIN(lc.department_id) AS agreed_department_id,
        COUNT(*) AS linked_case_count,
        COUNT(lc.department_id) AS assigned_department_count,
        COUNT(DISTINCT lc.department_id) AS distinct_department_count
    FROM team8_legal_documents ld
    JOIN team8_legal_cases lc ON lc.id = ld.case_id
    GROUP BY ld.document_id
    HAVING distinct_department_count = 0
        OR (distinct_department_count = 1 AND assigned_department_count = linked_case_count)
) agreed ON agreed.document_id = d.id
SET d.department_id = agreed.agreed_department_id;

-- Review shared documents linked to cases with conflicting departments.
SELECT
    d.id AS document_id,
    d.title AS document_title,
    d.department_id AS current_department_id,
    GROUP_CONCAT(
        DISTINCT CONCAT(lc.id, ':', COALESCE(CAST(lc.department_id AS CHAR), 'NULL'))
        ORDER BY lc.id SEPARATOR ', '
    ) AS linked_cases_and_departments
FROM team8_documents d
JOIN team8_legal_documents ld ON ld.document_id = d.id
JOIN team8_legal_cases lc ON lc.id = ld.case_id
GROUP BY d.id, d.title, d.department_id
HAVING COUNT(DISTINCT lc.department_id) > 1
    OR (COUNT(lc.department_id) > 0 AND COUNT(lc.department_id) < COUNT(*))
ORDER BY d.id;