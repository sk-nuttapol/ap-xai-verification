#!/usr/bin/env python3
"""Aggregate analysis of the Study 2 expert validation (protocol XAI-EXPERT-VALIDATION 2568-19f60b6395029d5b).

Input: a PRIVATE export of the study database (participants.tsv, phase1.tsv, ratings.tsv, samples.tsv produced by
`mysql -B`). The raw expert-level files are not part of this package (consent and exemption terms); this script and
the aggregate outputs are. Usage:  python3 expert_analysis.py <export_dir> <output_dir>
"""
import csv, json, math, sys, random
from collections import Counter, defaultdict

CATS = ['PASS', 'FAIL', 'INSUFFICIENT_DATA']
CORE = ['decision_agreement', 'explanation_consistency', 'explanation_clarity', 'explanation_completeness']

def unescape(v):  # mysql -B escaping
    out, i = [], 0
    while i < len(v):
        if v[i] == '\\' and i + 1 < len(v):
            out.append({'n': '\n', 't': '\t', '0': '\0', '\\': '\\'}.get(v[i + 1], v[i + 1])); i += 2
        else: out.append(v[i]); i += 1
    return ''.join(out)

def read(path):
    with open(path, encoding='utf-8', newline='\n') as f:
        lines = f.read().split('\n')
    head = lines[0].split('\t'); rows = []
    for ln in lines[1:]:
        if ln.strip(): rows.append({h: unescape(v) for h, v in zip(head, ln.split('\t'))})
    return rows

def num(v):
    try: return float(v)
    except (TypeError, ValueError): return None

def main(src, dst):
    P = read(f'{src}/participants.tsv'); F = read(f'{src}/phase1.tsv'); R = read(f'{src}/ratings.tsv'); S = read(f'{src}/samples.tsv')
    grp = {p['expert_code']: p['expert_group'] for p in P}
    ref = {s['simulation_case_id']: (s['case_code'], s['expected_decision'], s['difficulty_level']) for s in S}
    complete = sorted({r['expert_code'] for r in R})
    J = [dict(f, case=ref[f['simulation_case_id']][0], ref=ref[f['simulation_case_id']][1], diff=ref[f['simulation_case_id']][2])
         for f in F if f['expert_code'] in complete]
    for j in J: j['ok'] = j['oracle_decision'] == j['ref']
    n, k = len(J), sum(j['ok'] for j in J)
    cases = sorted({j['case'] for j in J}); experts = complete
    A = {(j['expert_code'], j['case']): int(j['ok']) for j in J}
    # chance-corrected agreement among experts
    m = len(experts); counts = {c: Counter(j['oracle_decision'] for j in J if j['case'] == c) for c in cases}
    Pi = [(sum(v * v for v in counts[c].values()) - m) / (m * (m - 1)) for c in cases]; Pa = sum(Pi) / len(Pi)
    pj = {c: sum(counts[x][c] for x in cases) / n for c in CATS}
    Pe = sum(v * v for v in pj.values()); kappa = (Pa - Pe) / (1 - Pe)
    Pe2 = sum(v * (1 - v) for v in pj.values()) / (len(CATS) - 1); ac1 = (Pa - Pe2) / (1 - Pe2)
    # crossed expert-by-case bootstrap
    rnd = random.Random(20261028); bs = []
    for _ in range(50000):
        es = [rnd.choice(experts) for _ in experts]; cs = [rnd.choice(cases) for _ in cases]
        bs.append(sum(A[(e, c)] for e in es for c in cs) / (len(es) * len(cs)))
    bs.sort(); ci = (bs[int(0.025 * len(bs))], bs[int(0.975 * len(bs)) - 1])
    # group permutation test (expert level)
    per = {e: sum(A[(e, c)] for c in cases) for e in experts}
    def diff(labels):
        hr = [e for e, g in zip(experts, labels) if g == 'HR_POLICY']; it = [e for e, g in zip(experts, labels) if g != 'HR_POLICY']
        return sum(per[e] for e in hr) / (12 * len(hr)) - sum(per[e] for e in it) / (12 * len(it))
    lab = [grp[e] for e in experts]; obs = diff(lab); rnd2 = random.Random(20261029)
    perm = sum(abs(diff(rnd2.sample(lab, len(lab)))) >= abs(obs) - 1e-12 for _ in range(100000)) / 100000
    summary = {'judgments': n, 'agreements': k, 'agreement': k / n, 'crossed_bootstrap_95ci': ci, 'fleiss_kappa': kappa,
               'gwet_ac1': ac1, 'observed_pairwise_agreement': Pa, 'group_difference': obs, 'permutation_p': perm,
               'experts_complete': len(experts), 'experts_all_correct': sum(per[e] == 12 for e in experts)}
    json.dump(summary, open(f'{dst}/agreement_summary.json', 'w'), indent=2)
    with open(f'{dst}/per_case_agreement.csv', 'w', newline='') as f:
        w = csv.writer(f); w.writerow(['case', 'difficulty_a_priori', 'reference', 'agree', 'n'])
        for c in cases:
            jj = [j for j in J if j['case'] == c]; w.writerow([c, jj[0]['diff'], jj[0]['ref'], sum(j['ok'] for j in jj), len(jj)])
    with open(f'{dst}/confusion_matrix.csv', 'w', newline='') as f:
        w = csv.writer(f); w.writerow(['reference'] + ['expert_' + c for c in CATS])
        for r_ in CATS: w.writerow([r_] + [sum(1 for j in J if j['ref'] == r_ and j['oracle_decision'] == c) for c in CATS])
    with open(f'{dst}/ratings_summary.csv', 'w', newline='') as f:
        w = csv.writer(f); w.writerow(['dimension', 'n', 'mean', 'sd', 'median', 'rated_4_5_pct'])
        for d in CORE:
            x = sorted(v for v in (num(r[d]) for r in R) if v is not None); mu = sum(x) / len(x)
            sd = math.sqrt(sum((v - mu) ** 2 for v in x) / (len(x) - 1)); med = x[len(x) // 2] if len(x) % 2 else (x[len(x) // 2 - 1] + x[len(x) // 2]) / 2
            w.writerow([d, len(x), round(mu, 2), round(sd, 2), med, round(100 * sum(v >= 4 for v in x) / len(x), 2)])
    with open(f'{dst}/participant_summary.csv', 'w', newline='') as f:
        w = csv.writer(f); w.writerow(['characteristic', 'value', 'HR_POLICY', 'IS_XAI'])
        for col in ['experience_band', 'rule_xai_experience', 'prior_review_experience']:
            for val in sorted({p[col] for p in P if p['expert_code'] in experts}):
                w.writerow([col, val] + [sum(1 for p in P if p['expert_code'] in experts and p[col] == val and p['expert_group'] == g) for g in ['HR_POLICY', 'IS_XAI']])
    print(json.dumps(summary, indent=2))

if __name__ == '__main__':
    main(sys.argv[1], sys.argv[2])
