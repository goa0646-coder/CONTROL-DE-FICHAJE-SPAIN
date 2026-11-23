-- Salida registrada por INDA cuando alguien no ficha la salida (se ve como «automática» y se puede corregir).
ALTER TABLE fichajes MODIFY origen ENUM('movil','web','quiosco','correccion','qr','api','chat','automatico') NOT NULL;
