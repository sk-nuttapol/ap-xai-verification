#!/usr/bin/env python3
# Uncertainty added at revision: crossed (expert x case) bootstrap CIs for Fleiss' kappa, Gwet's AC1,
# the four core phase-2 rating means and the decision-consistency minus clarity/completeness gaps,
# and an exact permutation test of the HR/policy vs IS/XAI agreement difference.
# Usage: python3 -I rv_ci.py <dir containing expert_analysis.py> <private export dir>
import sys, random, itertools
sys.path.insert(0, sys.argv[1])
from expert_analysis import read, num, CATS, CORE
D = sys.argv[2]
P = read(f'{D}/participants.tsv'); F = read(f'{D}/phase1.tsv'); R = read(f'{D}/ratings.tsv'); S = read(f'{D}/samples.tsv')
grp = {p['expert_code']: p['expert_group'] for p in P}
ref = {s['simulation_case_id']: s['expected_decision'] for s in S}
E = sorted({r['expert_code'] for r in R}); C = sorted({s['simulation_case_id'] for s in S})
dec = {(f['expert_code'], f['simulation_case_id']): f['oracle_decision'] for f in F if f['expert_code'] in E}
rat = {(r['expert_code'], r['simulation_case_id']): [num(r[d]) for d in CORE] for r in R}
assert len(E) == 21 and len(C) == 12 and len(dec) == 252
def agree_stats(es, cs):
    m = len(es); counts = []
    for c in cs:
        k = {x: 0 for x in CATS}
        for e in es: k[dec[(e, c)]] += 1
        counts.append(k)
    Pa = sum((sum(v * v for v in k.values()) - m) / (m * (m - 1)) for k in counts) / len(cs)
    pj = {x: sum(k[x] for k in counts) / (m * len(cs)) for x in CATS}
    Pe = sum(v * v for v in pj.values()); kappa = (Pa - Pe) / (1 - Pe)
    Pe2 = sum(v * (1 - v) for v in pj.values()) / (len(CATS) - 1); ac1 = (Pa - Pe2) / (1 - Pe2)
    return kappa, ac1
def rating_stats(es, cs):
    out = []
    for i in range(4):
        x = [rat[(e, c)][i] for e in es for c in cs if rat[(e, c)][i] is not None]
        out.append(sum(x) / len(x))
    return out + [out[0] - out[2], out[0] - out[3]]
k0, a0 = agree_stats(E, C); r0 = rating_stats(E, C)
rnd = random.Random(20261111); B = 50000; bk, ba, br = [], [], [[] for _ in range(6)]
for _ in range(B):
    es = [rnd.choice(E) for _ in E]; cs = [rnd.choice(C) for _ in C]
    try:
        k, a = agree_stats(es, cs)
    except ZeroDivisionError:
        k, a = 1.0, 1.0
    bk.append(k); ba.append(a)
    for i, v in enumerate(rating_stats(es, cs)): br[i].append(v)
def ci(v):
    v = sorted(v); return v[int(0.025 * len(v))], v[int(0.975 * len(v)) - 1]
print(f'kappa {k0:.3f} CI {ci(bk)[0]:.3f}-{ci(bk)[1]:.3f}')
print(f'AC1   {a0:.3f} CI {ci(ba)[0]:.3f}-{ci(ba)[1]:.3f}')
names = CORE + ['gap_consistency_minus_clarity', 'gap_consistency_minus_completeness']
for n, v0, v in zip(names, r0, br): print(f'{n:40s} {v0:.2f} CI {ci(v)[0]:.2f}-{ci(v)[1]:.2f}')
corr = {e: sum(dec[(e, c)] == ref[c] for c in C) for e in E}
hr = [e for e in E if grp[e] == 'HR_POLICY']
def diff(h): return sum(corr[e] for e in h) / (12 * len(h)) - sum(corr[e] for e in E if e not in h) / (12 * (len(E) - len(h)))
obs = diff(hr); n = ge = 0
for h in itertools.combinations(E, len(hr)):
    n += 1; ge += abs(diff(h)) >= abs(obs) - 1e-12
print(f'group diff {100*obs:.2f} pp; exact permutation p = {ge}/{n} = {ge/n:.4f}')
