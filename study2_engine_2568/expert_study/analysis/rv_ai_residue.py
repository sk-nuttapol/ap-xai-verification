# Post hoc response-process analysis (manuscript Section 4.8, Supplementary Table S10).
# Usage: python3 -I rv_ai_residue.py <dir containing expert_analysis.py> <private export dir> <output.json>
# The output holds judgment-level data; keep it private under the consent terms.
import sys, re, json, collections
sys.path.insert(0, sys.argv[1])
from expert_analysis import read
D = sys.argv[2]
smp = {r['simulation_case_id']: (r['case_code'], r['expected_decision']) for r in read(D + '/samples.tsv')}
rat = read(D + '/ratings.tsv')
complete = collections.Counter(r['expert_code'] for r in rat)
# Post hoc marker families for chat-assistant interaction residue, defined after reading the eight disagreement reasons
M = {
 'assistant_offer': r'(ส่งมาได้|ผมจะช่วย|ฉันจะช่วย|ช่วยวิเคราะห์|ช่วยหาคำตอบ|ช่วยเลือกคำตอบ|หากคุณมี|ถ้าคุณมี|ต้องการให้ผม|ต้องการให้ช่วย|ให้ผมช่วย)',
 'polite_particle_first_person': r'(ครับ|ค่ะ)\s*$|ผม(จะ|ขอ)',
 'ui_residue': r'(คัดลอก|Copy code|\bCopy\b|ChatGPT|Gemini|Claude|Copilot)',
 'form_fill_meta': r'(คำตอบสั้นสำหรับกรอก|สำหรับกรอกแบบ|กรอกในแบบฟอร์ม|คำตอบนี้เหมาะสมเมื่อ|คำตอบนี้ใช้ได้เมื่อ)',
 'no_case_seen': r'(ยังไม่มีข้อมูลโจทย์|ภาพโจทย์|ข้อมูลต้นฉบับของงาน|ยังไม่มีข้อเท็จจริงและเกณฑ์|ไม่มีผลลัพธ์ของระบบ|ยังไม่มีข้อมูลผลลัพธ์ของระบบ|รายละเอียดกรณีศึกษา เกณฑ์)',
 'checkbox_glyph': r'[☑✅✔✓■□]',
}
out = []
for r in read(D + '/phase1.tsv'):
    if complete[r['expert_code']] != 12: continue
    c, ref = smp[r['simulation_case_id']]
    t = r['oracle_reason']
    hits = [k for k, p in M.items() if re.search(p, t, re.M)]
    out.append(dict(e=r['expert_code'], case=c, ref=ref, dec=r['oracle_decision'], ok=r['oracle_decision'] == ref, hits=hits))
fl = [x for x in out if x['hits']]
print('judgments', len(out), 'with any marker', len(fl), 'experts with any marker', len({x['e'] for x in fl}))
for k in M: print('  %-28s %3d judgments, %2d experts' % (k, sum(k in x['hits'] for x in out), len({x['e'] for x in out if k in x['hits']})))
strong = {'assistant_offer', 'ui_residue', 'form_fill_meta', 'no_case_seen'}
st = [x for x in out if strong & set(x['hits'])]
print('strong-marker judgments', len(st), 'experts', sorted(collections.Counter(x['e'] for x in st).items()))
ag = lambda xs: '%d/%d (%.2f%%)' % (sum(x['ok'] for x in xs), len(xs), 100 * sum(x['ok'] for x in xs) / len(xs)) if xs else '-'
print('agreement strong-marked', ag(st), ' unmarked', ag([x for x in out if not (strong & set(x['hits']))]))
print('no_case_seen judgments:', [(x['e'], x['case'], x['dec'], x['ok']) for x in out if 'no_case_seen' in x['hits']])
print('disagreements and markers:', [(x['e'], x['case'], x['hits']) for x in out if not x['ok']])
json.dump(out, open(sys.argv[3], 'w'), ensure_ascii=False)
