-- The starting settlement is called "Main City"; settlements created before the
-- rename still carry the old name and would keep showing it forever.
UPDATE settlements SET name = 'Main City' WHERE name = 'Capital';
