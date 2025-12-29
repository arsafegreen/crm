import sqlite3
from pathlib import Path
DB_PATH = Path('storage/database.sqlite')
conn = sqlite3.connect(DB_PATH)
conn.execute('PRAGMA foreign_keys = OFF')
tables = ['whatsapp_messages','whatsapp_threads','whatsapp_contacts','whatsapp_lines']
counts_before = {}
cur = conn.cursor()
for table in tables:
    cur.execute(f'SELECT COUNT(*) FROM {table}')
    counts_before[table] = cur.fetchone()[0]
for table in tables:
    conn.execute(f'DELETE FROM {table}')
conn.execute("DELETE FROM sqlite_sequence WHERE name IN ({})".format(','.join(['?']*len(tables))), tables)
conn.commit()
print('Before:', counts_before)
print('After:', {table: conn.execute(f'SELECT COUNT(*) FROM {table}').fetchone()[0] for table in tables})
conn.close()
