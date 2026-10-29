p='d:/Development/Pos/frontend/features/dashboard/components/DashboardKpi.tsx'
s=open(p).read()
s=s.replace('gap-8 rounded-xl','gap-4 rounded-xl')
open(p,'w').write(s)
print('done')