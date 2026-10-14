-- The starting settlement is called "City Main"; settlements created before the
-- rename still carry the old name and would keep showing it forever.
UPDATE settlements SET name = 'City Main' WHERE name = 'Main City';
