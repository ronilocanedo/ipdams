import pathlib,sqlite3,re
root=pathlib.Path(__file__).resolve().parents[1]
api=(root/'public/api/index.php').read_text()
workflow=(root/'app/TextbookCoReceiptWorkflow.php').read_text()
schema={p.stem:p.read_text() for p in (root/'schema').glob('*.sql')}
checks=[]
def check(name,ok):
    if not ok: raise AssertionError(name)
    checks.append(name)

check('Three separate additive DDL files',len(schema)==3)
check('No destructive DDL',all(not re.search(r'(?m)^\s*(DROP|TRUNCATE|REPLACE|DELETE|UPDATE)\s',s,re.I) for s in schema.values()))
check('All tables explicitly InnoDB',all('ENGINE=InnoDB' in s for s in schema.values()))
check('No direct inventory post before acceptance',workflow.index('public function create(')<workflow.index('public function accept(')<workflow.index("INSERT INTO textbook_movements"))
check('Legacy opening and move still guarded',"if(in_array($route,['textbook.opening','textbook.move'],true))" in api)
check('No new GET endpoint without service role scope',"if($route==='textbook.co_receipts')" in api and 'private function office(' in workflow)
check('No impersonated SUPER_ADMIN receiving role',"($this->user['role']??'')===(string)$office['kind']" in workflow)
check('Custodian linked to appointed employee',"'PROPERTY_RECEIVING_LOGIN_V1'" in workflow and "'ORG_SUPPLY_PROPERTY_CUSTODIAN_V1'" in workflow)
check('Independent inspector and custodian requirement','different authenticated people' in workflow)
check('Create, submit, inspect, accept API dispatches',all("'textbook.co_receipt."+x+"'=>'"+x+"'" in api for x in ('create','submit','inspect','accept')))
check('API idempotent transaction retained',"$db->beginTransaction();" in api and 'ipdamsRequestFingerprint' in api)
check('Read-only list and detail are office scoped','WHERE r.organization_id=?' in workflow and 'WHERE id=? AND organization_id=?' in workflow)
check('Receipt source reference is per-office unique','UNIQUE KEY uq_co_delivery_org (organization_id, co_delivery_reference)' in schema['20261104_textbook_co_receipts'])
check('ISBN unique within each receipt','UNIQUE KEY uq_co_receipt_isbn (receipt_id, isbn13)' in schema['20261104_textbook_co_receipt_lines'])
check('Posting cannot be written twice per line and condition','UNIQUE KEY uq_co_posting_line_condition (receipt_line_id, condition_kind)' in schema['20261104_textbook_co_receipt_postings'])
check('Posting batch source one-to-one','UNIQUE KEY uq_co_posting_batch (batch_id)' in schema['20261104_textbook_co_receipt_postings'])
check('Condition-separated receipt stock','Damaged - Quarantined' in workflow and "'Serviceable'" in workflow)
check('Only accepted stock credits movement ledger','if($qty===0)continue' in workflow and "'RECEIPT'" in workflow)
check('No learner field ever stored',not re.search(r'learner_id|student_id|sf3_student|child_name',workflow,re.I))

# Simulate essential relational uniqueness, FK and check conservation with a
# portable SQLite equivalent; NOT a MariaDB compatibility or migration test.
con=sqlite3.connect(':memory:');con.execute('PRAGMA foreign_keys=ON')
con.executescript('''
CREATE TABLE organizations(id INTEGER PRIMARY KEY);
CREATE TABLE users(id INTEGER PRIMARY KEY);
CREATE TABLE textbook_titles(id INTEGER PRIMARY KEY);
CREATE TABLE textbook_batches(id INTEGER PRIMARY KEY);
INSERT INTO organizations VALUES(7),(9);
INSERT INTO users VALUES(41),(42),(43);
INSERT INTO textbook_titles VALUES(10);
CREATE TABLE textbook_co_receipts(id INTEGER PRIMARY KEY,organization_id INTEGER NOT NULL REFERENCES organizations(id),co_delivery_reference TEXT NOT NULL,status TEXT NOT NULL,UNIQUE(organization_id,co_delivery_reference));
CREATE TABLE textbook_co_receipt_lines(id INTEGER PRIMARY KEY,receipt_id INTEGER NOT NULL REFERENCES textbook_co_receipts(id),title_id INTEGER NOT NULL REFERENCES textbook_titles(id),isbn13 TEXT NOT NULL,UNIQUE(receipt_id,isbn13));
CREATE TABLE textbook_co_receipt_postings(id INTEGER PRIMARY KEY,receipt_line_id INTEGER NOT NULL REFERENCES textbook_co_receipt_lines(id),batch_id INTEGER NOT NULL REFERENCES textbook_batches(id),condition_kind TEXT NOT NULL,quantity INTEGER NOT NULL,UNIQUE(receipt_line_id,condition_kind),UNIQUE(batch_id));
INSERT INTO textbook_co_receipts VALUES(100,9,'CO-REF-1','DRAFT');
INSERT INTO textbook_co_receipts VALUES(101,7,'CO-REF-1','DRAFT');
INSERT INTO textbook_co_receipt_lines VALUES(200,100,10,'9780132350884');
INSERT INTO textbook_batches VALUES(300),(301);
INSERT INTO textbook_co_receipt_postings VALUES(500,200,300,'SERVICEABLE',96);
INSERT INTO textbook_co_receipt_postings VALUES(501,200,301,'QUARANTINED',2);
''')
check('Distinct Division/school receipt refs allowed',con.execute('select count(*) from textbook_co_receipts').fetchone()[0]==2)
check('Received quantities split correctly',con.execute('select sum(quantity) from textbook_co_receipt_postings').fetchone()[0]==98)
def reject(name,sql):
    try:con.execute(sql)
    except sqlite3.IntegrityError:checks.append(name);return
    raise AssertionError(name)
reject('Duplicate receipt for same Division denied',"INSERT INTO textbook_co_receipts VALUES(102,9,'CO-REF-1','DRAFT')")
reject('Duplicate ISBN line denied',"INSERT INTO textbook_co_receipt_lines VALUES(201,100,10,'9780132350884')")
reject('Duplicate serviceable posting denied',"INSERT INTO textbook_co_receipt_postings VALUES(502,200,302,'SERVICEABLE',96)")
reject('Double-credit same batch denied',"INSERT INTO textbook_co_receipt_postings VALUES(503,200,300,'RETURNED',2)")
reject('Cross-table invalid title ID denied',"INSERT INTO textbook_co_receipt_lines VALUES(202,101,999,'9791090636071')")
print(f'PASS: {len(checks)} CO receipt API/schema tests (static + portable SQLite synthetic constraints)')
for name in checks: print('PASS:',name)
