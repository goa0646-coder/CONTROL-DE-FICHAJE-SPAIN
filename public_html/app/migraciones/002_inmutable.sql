CREATE TRIGGER fichajes_no_update BEFORE UPDATE ON fichajes FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Los fichajes no se pueden modificar';
CREATE TRIGGER fichajes_no_delete BEFORE DELETE ON fichajes FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Los fichajes no se pueden borrar';
CREATE TRIGGER auditoria_no_update BEFORE UPDATE ON auditoria FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La auditoria no se puede modificar';
CREATE TRIGGER auditoria_no_delete BEFORE DELETE ON auditoria FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La auditoria no se puede borrar';
