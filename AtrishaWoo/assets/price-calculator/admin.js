(function () {
	'use strict';

	const root = document.getElementById('mgmp-admin-root');
	if (!root) return;
	if (!window.mgmpAdmin || !window.React || !window.ReactDOM || !window.ReactDOM.createRoot || !window.MaterialUI) {
		root.innerHTML = '<div class="notice notice-error inline"><p>Price Calculator admin could not load because a required JavaScript dependency or configuration object is missing. Please refresh the page or check browser console/network errors.</p></div>';
		return;
	}
	mgmpAdmin.config = mgmpAdmin.config || {};
	mgmpAdmin.config.pricing = mgmpAdmin.config.pricing || mgmpAdmin.pricing || {};

	const { createElement: h, useCallback, useEffect, useMemo, useState } = React;
	const M = MaterialUI;
	const fields = [
		['gram_price_100', 'Gram price 100ml'], ['gram_price_50', 'Gram price 50ml'], ['gram_price_30', 'Gram price 30ml'], ['gram_price_10', 'Gram price 10ml'],
		['fixative_price', 'Fixative price'], ['bottle_price', 'Bottle price'], ['packaging_price', 'Packaging price'], ['shipping_cost', 'Shipping cost'], ['tax_percent', 'Tax percent']
	];

	function post(action, data) {
		const body = new URLSearchParams(Object.assign({ action, nonce: mgmpAdmin.nonce }, data || {}));
		return fetch(mgmpAdmin.ajaxUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' }, body })
			.then((r) => r.json()).then((json) => { if (!json.success) throw new Error((json.data && json.data.message) || 'Request failed'); return json.data; });
	}

	function MetricCard({ title, value, tone }) {
		return h(M.Card, { elevation: 0, sx: { border: '1px solid', borderColor: 'divider', borderRadius: 3, height: '100%' } },
			h(M.CardContent, null,
				h(M.Stack, { direction: 'row', justifyContent: 'space-between', alignItems: 'center' }, h(M.Typography, { color: 'text.secondary', variant: 'body2' }, title), h(M.Chip, { size: 'small', color: tone || 'primary', label: 'Live' })),
				value === null ? h(M.Skeleton, { variant: 'text', width: 96, height: 48 }) : h(M.Typography, { variant: 'h4', fontWeight: 700, sx: { mt: 1 } }, value)
			)
		);
	}

	function ProgressPanel({ status, onStart, busy }) {
		const percent = Number(status.percent || 0);
		return h(M.Card, { elevation: 0, sx: { border: '1px solid', borderColor: 'divider', borderRadius: 3 } }, h(M.CardContent, null,
			h(M.Stack, { direction: { xs: 'column', sm: 'row' }, justifyContent: 'space-between', spacing: 2 },
				h(M.Box, null, h(M.Typography, { variant: 'h6', fontWeight: 700 }, 'Realtime Batch Processor'), h(M.Typography, { color: 'text.secondary' }, `${status.processed || 0} / ${status.total || 0} processed • ${status.failed || 0} failed`)),
				h(M.Stack, { direction: 'row', spacing: 1 }, h(M.Chip, { color: percent >= 100 ? 'success' : 'warning', label: `${percent}%` }), h(M.Button, { variant: 'contained', disabled: busy, onClick: onStart }, busy ? h(M.CircularProgress, { size: 20 }) : 'Start Batch'))
			),
			h(M.LinearProgress, { variant: 'determinate', value: Math.min(100, percent), sx: { height: 12, borderRadius: 999, my: 3 } }),
			h(M.Typography, { variant: 'body2', color: 'text.secondary' }, `Current SKU: ${status.current_sku || '—'} • Started at: ${status.started_at || '—'}`)
		));
	}

	function PricingForm({ pricing, setPricing, notify }) {
		const [saving, setSaving] = useState(false);
		const invalid = fields.some(([key]) => Number(pricing[key]) < 0 || pricing[key] === '');
		function save() { setSaving(true); post('mgmp_save_pricing', pricing).then((d) => { setPricing(d.pricing); notify(d.message, 'success'); }).catch((e) => notify(e.message, 'error')).finally(() => setSaving(false)); }
		return h(M.Card, { elevation: 0, sx: { border: '1px solid', borderColor: 'divider', borderRadius: 3 } }, h(M.CardContent, null,
			h(M.Typography, { variant: 'h6', fontWeight: 700, mb: 2 }, 'Pricing Inputs'),
			h(M.Grid, { container: true, spacing: 2 }, fields.map(([key, label]) => h(M.Grid, { item: true, xs: 12, sm: 6, md: 4, key }, h(M.TextField, { fullWidth: true, type: 'number', label, value: pricing[key] ?? '', error: Number(pricing[key]) < 0 || pricing[key] === '', helperText: Number(pricing[key]) < 0 ? 'Must be zero or greater' : ' ', onChange: (e) => setPricing(Object.assign({}, pricing, { [key]: e.target.value })) })))),
			h(M.Divider, { sx: { my: 2 } }), h(M.Button, { variant: 'contained', disabled: saving || invalid, onClick: save }, saving ? h(M.CircularProgress, { size: 20 }) : 'Save')
		));
	}

	function SKUProcessor({ pricing, notify }) {
		const [sku, setSku] = useState(''); const [loading, setLoading] = useState(false); const [result, setResult] = useState(null);
		function run(mode) { setLoading(true); post('mgmp_enqueue_sku', Object.assign({}, pricing, { sku, mode })).then((d) => { setResult(d); notify(d.message, 'success'); }).catch((e) => notify(e.message, 'error')).finally(() => setLoading(false)); }
		return h(M.Card, { elevation: 0, sx: { border: '1px solid', borderColor: 'divider', borderRadius: 3 } }, h(M.CardContent, null,
			h(M.Typography, { variant: 'h6', fontWeight: 700, mb: 2 }, 'SKU Processor'), h(M.Stack, { direction: { xs: 'column', md: 'row' }, spacing: 2 }, h(M.TextField, { fullWidth: true, label: 'SKU input', value: sku, onChange: (e) => setSku(e.target.value), inputProps: { 'aria-label': 'SKU input' } }), h(M.Button, { variant: 'contained', disabled: loading || !sku, onClick: () => run('single') }, 'Calculate This Product'), h(M.Button, { variant: 'outlined', disabled: loading || !sku, onClick: () => run('parent') }, 'Queue Entire Parent')),
			loading && h(M.LinearProgress, { sx: { mt: 2 } }), result && h(M.Alert, { severity: 'success', sx: { mt: 2 } }, `Parent product: ${result.parent_product_name} • Variations updated: ${result.variations_updated} • Time taken: ${result.time_taken}s`)
		));
	}

	function Dashboard({ data }) { const m = data.metrics || {}, c = data.counts || {}; return h(M.Grid, { container: true, spacing: 2 }, [['Total Products', m.total_products], ['Total Variations', m.total_variations], ['Last Batch Run', (data.batch_status || {}).started_at || '—'], ['Jobs Pending', c.pending], ['Jobs Completed', c.done, 'success']].map(([t, v, tone]) => h(M.Grid, { item: true, xs: 12, sm: 6, md: 4, key: t }, h(MetricCard, { title: t, value: v ?? null, tone })))); }
	function Logs({ notify }) { const [rows, setRows] = useState([]), [search, setSearch] = useState(''), [level, setLevel] = useState('all'); useEffect(() => { const id = setTimeout(() => post('mgmp_logs', { search, level }).then((d) => setRows(d.rows)).catch((e) => notify(e.message, 'error')), 300); return () => clearTimeout(id); }, [search, level]); return h(M.Stack, { spacing: 2 }, h(M.Stack, { direction: 'row', spacing: 2 }, h(M.TextField, { label: 'Search logs', value: search, onChange: (e) => setSearch(e.target.value) }), h(M.TextField, { select: true, label: 'Level', value: level, onChange: (e) => setLevel(e.target.value), sx: { minWidth: 160 } }, ['all', 'info', 'warning', 'error', 'failed'].map((x) => h(M.MenuItem, { value: x, key: x }, x)))), h(M.TableContainer, { component: M.Paper, sx: { borderRadius: 3, maxHeight: 560 } }, h(M.Table, { stickyHeader: true, size: 'small' }, h(M.TableHead, null, h(M.TableRow, null, h(M.TableCell, null, 'Date / Message'), h(M.TableCell, null, 'Actions'))), h(M.TableBody, null, rows.map((row, i) => h(M.TableRow, { key: i, hover: true }, h(M.TableCell, { sx: { fontFamily: 'monospace', direction: 'ltr' } }, row), h(M.TableCell, null, h(M.Button, { size: 'small', onClick: () => navigator.clipboard && navigator.clipboard.writeText(row) }, 'Copy')))))))); }
	function Settings() { return h(M.Stack, { spacing: 2 }, h(M.Alert, { severity: 'info' }, 'Legacy CSV import remains available and feeds the existing queue/batch processor.'), h(M.Card, { elevation: 0, sx: { border: '1px solid', borderColor: 'divider', borderRadius: 3 } }, h(M.CardContent, null, h(M.Typography, { variant: 'h6', fontWeight: 700, mb: 2 }, 'CSV Import'), h('form', { method: 'post', action: mgmpAdmin.adminPostUrl, encType: 'multipart/form-data' }, h('input', { type: 'hidden', name: 'action', value: 'mgmp_upload_csv' }), h('input', { type: 'hidden', name: '_wpnonce', value: mgmpAdmin.uploadNonce }), h(M.Stack, { direction: { xs: 'column', sm: 'row' }, spacing: 2, alignItems: 'center' }, h(M.Button, { variant: 'outlined', component: 'label' }, 'Choose CSV', h('input', { hidden: true, type: 'file', name: 'mgmp_csv', accept: '.csv,text/csv', required: true })), h(M.Button, { type: 'submit', variant: 'contained' }, 'Upload CSV'))))), h(M.Card, { elevation: 0, sx: { border: '1px solid', borderColor: 'divider', borderRadius: 3 } }, h(M.CardContent, null, h(M.Typography, { variant: 'h6', fontWeight: 700, mb: 2 }, 'Fallback Processing Form'), h('form', { method: 'post', action: mgmpAdmin.adminPostUrl }, h('input', { type: 'hidden', name: 'action', value: 'mgmp_start_processing' }), h('input', { type: 'hidden', name: '_wpnonce', value: mgmpAdmin.processNonce }), h(M.Button, { type: 'submit', variant: 'outlined' }, 'Start processing'))))); }

	function App() {
		const [page, setPage] = useState('Dashboard'), [mode, setMode] = useState(localStorage.getItem('mgmpMode') || 'light'), [data, setData] = useState({}), [pricing, setPricing] = useState(mgmpAdmin.config.pricing || {}), [snack, setSnack] = useState(null), [busy, setBusy] = useState(false);
		const theme = useMemo(() => M.createTheme({ direction: 'rtl', palette: { mode, primary: { main: '#4f46e5' }, success: { main: '#16a34a' }, warning: { main: '#f97316' }, error: { main: '#dc2626' } }, shape: { borderRadius: 12 }, spacing: 8, typography: { fontFamily: 'Inter, Tahoma, Arial, sans-serif' } }), [mode]);
		const notify = useCallback((message, severity) => setSnack({ message, severity }), []);
		const refresh = useCallback(() => post('mgmp_status').then(setData).catch((e) => notify(e.message, 'error')), [notify]);
		useEffect(() => { refresh(); const id = setInterval(refresh, 2000); return () => clearInterval(id); }, [refresh]);
		function startBatch() { setBusy(true); post('mgmp_start_batch').then((d) => { notify(d.message, 'success'); refresh(); }).catch((e) => notify(e.message, 'error')).finally(() => setBusy(false)); }
		const content = page === 'Dashboard' ? h(Dashboard, { data }) : page === 'Pricing Engine' ? h(M.Stack, { spacing: 2 }, h(PricingForm, { pricing, setPricing, notify }), h(SKUProcessor, { pricing, notify })) : page === 'Batch Processor' ? h(ProgressPanel, { status: data.batch_status || {}, onStart: startBatch, busy }) : page === 'Logs' ? h(Logs, { notify }) : h(Settings);
		return h(M.ThemeProvider, { theme }, h(M.CssBaseline), h(M.Box, { sx: { display: 'flex', minHeight: 'calc(100vh - 32px)', bgcolor: 'background.default' } },
			h(M.Drawer, { variant: 'permanent', anchor: 'left', PaperProps: { sx: { width: 248, borderRight: '1px solid', borderColor: 'divider' } } }, h(M.Toolbar, null, h(M.Typography, { variant: 'h6', fontWeight: 800 }, 'Price Calculator')), h(M.List, null, ['Dashboard', 'Pricing Engine', 'Batch Processor', 'Logs', 'Settings'].map((x) => h(M.ListItemButton, { key: x, selected: page === x, onClick: () => setPage(x) }, h(M.ListItemText, { primary: x }))))),
			h(M.Box, { component: 'main', sx: { flexGrow: 1, p: 3, ml: '248px' } }, h(M.AppBar, { position: 'sticky', color: 'inherit', elevation: 0, sx: { border: '1px solid', borderColor: 'divider', borderRadius: 3, mb: 3 } }, h(M.Toolbar, null, h(M.Typography, { variant: 'h6', fontWeight: 800, sx: { flexGrow: 1 } }, page), h(M.Chip, { label: mgmpAdmin.config.environment, color: mgmpAdmin.config.environment === 'production' ? 'success' : 'warning', sx: { mr: 1 } }), h(M.Chip, { label: 'v' + mgmpAdmin.config.version, sx: { mr: 2 } }), h(M.Switch, { checked: mode === 'dark', inputProps: { 'aria-label': 'Dark mode toggle' }, onChange: () => { const next = mode === 'dark' ? 'light' : 'dark'; localStorage.setItem('mgmpMode', next); setMode(next); } }))), content)),
			snack && h(M.Snackbar, { open: true, autoHideDuration: 4000, onClose: () => setSnack(null) }, h(M.Alert, { severity: snack.severity || 'info', onClose: () => setSnack(null) }, snack.message)));
	}

	root.innerHTML = '';
	ReactDOM.createRoot(root).render(h(App));
}());
