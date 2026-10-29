# Post hoc response-process analysis (manuscript Section 4.8, Supplementary Table S10).
# Usage: python3 -I rv_sensitivity.py <dir containing expert_analysis.py> <private export dir> <output.json>
# The output holds judgment-level data; keep it private under the consent terms.
import sys, re, statistics as st, json
sys.path.insert(0, sys.argv[1])
from expert_analysis import read
D = sys.argv[2]
p1 = read(f'{D}/phase1.tsv'); smp = read(f'{D}/samples.tsv'); par = {r['expert_code']: r for r in read(f'{D}/participants.tsv')}
rat = read(f'{D}/ratings.tsv')
ref = {}; code = {}
for r in smp:
    cid = r['simulation_case_id']; ref[cid] = r['expected_decision']; code[cid] = r['case_code']; 
from datetime import datetime
def ts(s): return datetime.fromisoformat(s.replace(' ', 'T'))
by = {}
for r in p1: by.setdefault(r['expert_code'], []).append(r)
complete = {e for e, rows in by.items() if len(rows) == 12 and sum(1 for x in rat if x['expert_code']==e) == 12}
J = []
for e in sorted(complete):
    rows = sorted(by[e], key=lambda x: x['submitted_at'])
    for i, r in enumerate(rows):
        n = len(r['oracle_reason'])
        rate = None
        if i: 
            dt = (ts(r['submitted_at']) - ts(rows[i-1]['submitted_at'])).total_seconds()
            rate = n / dt if dt > 0 else None
        txt = r['oracle_reason']
        md = bool(re.search(r'(\*\*|^\s*[-•*]\s|^\s*#|^\s*\d+[.)]\s)', txt, re.M))
        J.append(dict(e=e, g=par[e]['expert_group'], case=code[r['simulation_case_id']], ref=ref[r['simulation_case_id']],
                      dec=r['oracle_decision'], ok=r['oracle_decision']==ref[r['simulation_case_id']], n=n, rate=rate, md=md, first=(i==0)))
def agree(xs): return f"{sum(x['ok'] for x in xs)}/{len(xs)} ({100*sum(x['ok'] for x in xs)/len(xs):.2f}%)" if xs else '-'
print('experts', len(complete), 'judgments', len(J), 'overall', agree(J))
R = [x for x in J if x['rate'] is not None]
print('rate<=8 cps', agree([x for x in R if x['rate']<=8]), ' rate>8 cps', agree([x for x in R if x['rate']>8]), ' first submissions', agree([x for x in J if x['first']]))
print('rate<=3 cps (plausibly typed live)', agree([x for x in R if x['rate']<=3]))
# per expert
E = {}
for x in J: E.setdefault(x['e'], []).append(x)
rows=[]
for e, xs in E.items():
    rr=[x['rate'] for x in xs if x['rate'] is not None]
    rows.append((e, xs[0]['g'], st.median(rr), sum(x['ok'] for x in xs), sum(x['md'] for x in xs), st.median([x['n'] for x in xs])))
rows.sort(key=lambda r: r[2])
for r in rows: print('  %s %-9s median_cps=%5.1f correct=%2d/12 markdown=%2d median_len=%d' % r)
lo = [r for r in rows[:len(rows)//2]]; hi = rows[len(rows)//2:]
print('lower-half rate experts', sum(r[3] for r in lo), '/', 12*len(lo), ' upper-half', sum(r[3] for r in hi), '/', 12*len(hi))
# spearman
def rank(v):
    s=sorted(range(len(v)), key=lambda i:v[i]); r=[0]*len(v); i=0
    while i<len(s):
        j=i
        while j+1<len(s) and v[s[j+1]]==v[s[i]]: j+=1
        for k in range(i,j+1): r[s[k]]=(i+j)/2+1
        i=j+1
    return r
a=rank([r[2] for r in rows]); b=rank([r[3] for r in rows]); n=len(rows)
ma=sum(a)/n; mb=sum(b)/n
rho=sum((x-ma)*(y-mb) for x,y in zip(a,b))/(sum((x-ma)**2 for x in a)*sum((y-mb)**2 for y in b))**.5
print('spearman(rate, correct) =', round(rho,3))
print('markdown-formatted reasons', sum(x['md'] for x in J), '/', len(J), ' agreement md', agree([x for x in J if x['md']]), ' no-md', agree([x for x in J if not x['md']]))
print('disagreements:')
for x in J:
    if not x['ok']: print('  ', x['e'], x['g'], x['case'], 'ref', x['ref'], '->', x['dec'], 'cps', None if x['rate'] is None else round(x['rate'],1))
# revisions
rev=[(x['expert_code'], x['post_explanation_revision']) for x in rat if x['expert_code'] in complete and x['post_explanation_revision'] not in ('', 'NULL', 'NO_CHANGE', None)]
print('post-explanation revisions', len(rev), rev[:10])
json.dump(J, open(sys.argv[3],'w'), ensure_ascii=False)
