(function () {
	'use strict';

	var mount = document.getElementById('atrishawoo-mui-shell');
	if (!mount || !window.AtrishaWooShell || !window.React || !window.ReactDOM || !window.MaterialUI) {
		return;
	}

	var cfg = window.AtrishaWooShell;
	var h = React.createElement;
	var M = MaterialUI;

	function Shell() {
		var tabs = cfg.tabs || [];
		var active = cfg.active || 'sku';

		return h(
			M.ThemeProvider,
			{
				theme: M.createTheme({
					direction: 'rtl',
					palette: { mode: 'light', primary: { main: '#4f46e5' } },
					shape: { borderRadius: 12 },
					typography: { fontFamily: 'Inter, Tahoma, Arial, sans-serif' },
				}),
			},
			h(M.CssBaseline),
			h(
				M.Paper,
				{
					elevation: 0,
					sx: {
						border: '1px solid',
						borderColor: 'divider',
						borderRadius: 3,
						mb: 2,
						overflow: 'hidden',
					},
				},
				h(
					M.Stack,
					{
						direction: { xs: 'column', sm: 'row' },
						alignItems: { xs: 'stretch', sm: 'center' },
						justifyContent: 'space-between',
						sx: { px: 2, py: 1.5, gap: 1 },
					},
					h(M.Typography, { variant: 'h6', fontWeight: 800 }, 'AtrishaWoo'),
					h(
						M.Tabs,
						{
							value: active,
							variant: 'scrollable',
							scrollButtons: 'auto',
							sx: { minHeight: 42 },
						},
						tabs.map(function (tab) {
							return h(M.Tab, {
								key: tab.id,
								value: tab.id,
								label: tab.label,
								href: tab.url,
								component: 'a',
							});
						})
					)
				)
			)
		);
	}

	mount.innerHTML = '';
	ReactDOM.createRoot(mount).render(h(Shell));
})();
