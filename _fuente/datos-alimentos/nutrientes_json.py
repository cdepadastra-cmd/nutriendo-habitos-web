# Genera nutrientes.json para la tabla «Alimentos ricos en proteínas, vitaminas y omega 3».
# Toma los alimentos de alimentos.json (nombres en español, ración) y sus vitaminas, minerales y
# omega 3 de usda_sr.json (USDA FoodData Central SR Legacy, dominio público), unidos por el id FDC.
# None = la base no tiene ese dato para ese alimento.
import json, os, sys
B = os.path.dirname(os.path.abspath(__file__))
A = json.load(open(os.path.join(B, 'alimentos.json'), encoding='utf-8'))
U = {x['id']: x for x in json.load(open(os.path.join(B, 'usda_sr.json'), encoding='utf-8'))}
FUERA = {'Bebidas', 'Dulces y otros'}
# Mayonesa y margarina: en EE. UU. se hacen con aceite de soja/colza y su omega 3 no representa a las españolas.
QUITAR = {'Mayonesa', 'Margarina'}
NOMBRE = {'Cereales de desayuno tipo corn flakes': 'Cereales de desayuno enriquecidos (tipo corn flakes)'}
def r(v, d=1):
    return None if v is None else round(v, d)
filas = []
for a in A['a']:
    if a[1] in FUERA or a[0] in QUITAR: continue
    u = U.get(a[12])
    if not u: continue
    ala = u.get('ala', u.get('ala_tot'))
    partes = [ala, u.get('epa'), u.get('dha'), u.get('dpa')]
    om3 = None if all(p is None for p in partes) else sum(p or 0 for p in partes)
    lc = [u.get('epa'), u.get('dha'), u.get('dpa')]
    epadha = None if all(p is None for p in lc) else 1000 * sum(p or 0 for p in lc)
    filas.append([NOMBRE.get(a[0], a[0]), a[1], a[2], r(u.get('prot')), r(u.get('b12'), 2), r(u.get('b6'), 2), r(u.get('vitc')), r(u.get('vitd')),
                  r(om3, 2), r(epadha, 0), r(u.get('hierro')), r(u.get('calcio'), 0), r(u.get('folato_dfe', u.get('folato')), 0),
                  r(u.get('fibra')), a[10], a[11]])
cats = [c for c in A['cats'] if c not in FUERA]
out = {'cats': cats, 'a': filas}
json.dump(out, open(sys.argv[1] if len(sys.argv) > 1 else os.path.join(B, 'nutrientes.json'), 'w', encoding='utf-8'), ensure_ascii=False, separators=(',', ':'))
print(len(filas), 'alimentos')
