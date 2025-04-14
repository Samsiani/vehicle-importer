(function(wp) {
    const { useEffect, useState } = wp.element;
    const apiFetch = wp.apiFetch;

    function VehicleImporterApp() {
        const [status, setStatus] = useState({});
        const [logs, setLogs] = useState([]);
        const [loading, setLoading] = useState(false);
        const [message, setMessage] = useState('');
        const [resetting, setResetting] = useState(false);
        const [paused, setPaused] = useState(false);
        const [batchSize, setBatchSize] = useState(10);
        const [manualVin, setManualVin] = useState('');
        const [manualLoading, setManualLoading] = useState(false);
        const [vehicles, setVehicles] = useState([]);
        const [search, setSearch] = useState('');
        const [currentPage, setCurrentPage] = useState(1);
        const itemsPerPage = 10;

        const fetchData = () => {
            apiFetch({ path: 'vehicle-importer/v1/status' })
                .then(data => {
                    setStatus(data);
                    setPaused(data.paused);
                    setBatchSize(data.batch_size);
                })
                .catch(console.error);

            apiFetch({ path: 'vehicle-importer/v1/logs' })
                .then(data => setLogs(data.logs))
                .catch(console.error);

            apiFetch({ path: 'vehicle-importer/v1/all-vehicles' })
                .then(setVehicles)
                .catch(console.error);
        };

        useEffect(() => {
            fetchData();
            const interval = setInterval(fetchData, 30000);
            return () => clearInterval(interval);
        }, []);

        const runNow = () => {
            setLoading(true);
            setMessage('');
            apiFetch({
                path: 'vehicle-importer/v1/run-now',
                method: 'POST',
                headers: { 'X-WP-Nonce': vehicleImporterData.nonce }
            })
                .then(res => {
                    setMessage(res.message);
                    fetchData();
                })
                .catch(() => setMessage('❌ შესრულება ვერ მოხერხდა'))
                .finally(() => setLoading(false));
        };

        const resetOffset = () => {
            setResetting(true);
            apiFetch({
                path: 'vehicle-importer/v1/reset-offset',
                method: 'POST',
                headers: { 'X-WP-Nonce': vehicleImporterData.nonce }
            })
                .then(res => {
                    setMessage(res.message);
                    fetchData();
                })
                .catch(() => setMessage('❌ Offset-ის გადაყვანა ვერ მოხერხდა'))
                .finally(() => setResetting(false));
        };

        const togglePause = () => {
            apiFetch({
                path: 'vehicle-importer/v1/toggle-pause',
                method: 'POST',
                headers: { 'X-WP-Nonce': vehicleImporterData.nonce }
            })
                .then(res => {
                    setPaused(res.paused);
                    setMessage(res.paused ? '🛑 იმპორტი შეჩერებულია' : '▶️ იმპორტი განახლდა');
                    fetchData();
                })
                .catch(() => setMessage('❌ შეჩერება/გაგრძელება ვერ მოხერხდა'));
        };

        const updateBatchSize = (e) => {
            const newSize = parseInt(e.target.value);
            setBatchSize(newSize);
            apiFetch({
                path: 'vehicle-importer/v1/batch-size',
                method: 'POST',
                headers: { 'X-WP-Nonce': vehicleImporterData.nonce },
                data: { size: newSize }
            })
                .then(res => setMessage(res.message))
                .catch(() => setMessage('❌ Batch size-ის შეცვლა ვერ მოხერხდა'));
        };

        const handleManualImport = () => {
            if (!manualVin.trim()) {
                setMessage('❗ VIN არ არის შეყვანილი');
                return;
            }
            setManualLoading(true);
            setMessage('');
            apiFetch({
                path: 'vehicle-importer/v1/manual-import',
                method: 'POST',
                headers: { 'X-WP-Nonce': vehicleImporterData.nonce },
                data: { vin: manualVin.trim() }
            })
                .then(res => {
                    setMessage(res.message);
                    setManualVin('');
                    fetchData();
                })
                .catch(() => setMessage('❌ VIN იმპორტი ვერ მოხერხდა'))
                .finally(() => setManualLoading(false));
        };

        const handleVinKeyPress = (e) => {
            if (e.key === 'Enter') handleManualImport();
        };

        const filtered = vehicles.filter((v) =>
            v.vin.toLowerCase().includes(search.toLowerCase())
        );

        const totalPages = Math.ceil(filtered.length / itemsPerPage);
        const currentItems = filtered.slice((currentPage - 1) * itemsPerPage, currentPage * itemsPerPage);

        return wp.element.createElement('div', null,
            // TOP
            wp.element.createElement('div', null,
                wp.element.createElement('button', {
                    className: 'button button-primary', onClick: runNow, disabled: loading
                }, loading ? '⏳ იმპორტი მიმდინარეობს...' : '🚀 გაუშვი ახლავე'),

                wp.element.createElement('button', {
                    className: 'button', onClick: resetOffset, style: { marginLeft: '10px' }, disabled: resetting
                }, resetting ? '⏳ გადაყვანა...' : '🔄 გადატვირთე იმპორტის ციკლი'),

                wp.element.createElement('button', {
                    className: 'button', onClick: togglePause, style: { marginLeft: '10px' }
                }, paused ? '▶️ იმპორტის დაწყება' : '🛑 იმპორტის შეჩერება')
            ),

            wp.element.createElement('div', { style: { marginTop: '1em' } },
                wp.element.createElement('label', null, '📦 იმპორტის სიჩქარე: '),
                wp.element.createElement('select', {
                    value: batchSize,
                    onChange: updateBatchSize,
                    style: { marginLeft: '10px' }
                }, [10, 20, 30, 50].map(size =>
                    wp.element.createElement('option', { key: size, value: size }, size)
                ))
            ),

            wp.element.createElement('div', { style: { marginTop: '2em' } },
                wp.element.createElement('label', null, '🔍 Manual VIN Import:'),
                wp.element.createElement('input', {
                    type: 'text', value: manualVin, onChange: (e) => setManualVin(e.target.value),
                    onKeyDown: handleVinKeyPress,
                    style: { marginLeft: '10px', marginRight: '10px' },
                    placeholder: 'მაგ. JT12345678900000'
                }),
                wp.element.createElement('button', {
                    className: 'button', onClick: handleManualImport, disabled: manualLoading
                }, manualLoading ? '⏳ მიმდინარეობს...' : '📥 იმპორტი VIN-ით')
            ),

            message && wp.element.createElement('p', { style: { marginTop: '1em' } },
                wp.element.createElement('strong', null, message)
            ),

            wp.element.createElement('div', { style: { marginTop: '2em' } },
                wp.element.createElement('h3', null, '📊 სტატუსი'),
                wp.element.createElement('p', null,
                    wp.element.createElement('strong', null, 'Next Run: '),
                    status.next_run ? new Date(status.next_run * 1000).toLocaleString() : '—'
                ),
                wp.element.createElement('p', null,
                    wp.element.createElement('strong', null, 'Current Offset: '),
                    status.offset ?? '—'
                )
            ),

            wp.element.createElement('div', { style: { marginTop: '2em' } },
                wp.element.createElement('h3', null, '📜 ბოლო ლოგები'),
                wp.element.createElement('pre', {
                    style: {
                        background: '#fff', border: '1px solid #ccc', padding: '10px',
                        maxHeight: '300px', overflow: 'auto'
                    }
                }, logs.length ? logs.map(line => line.trim()).join('\n') : 'ლოგები არ მოიძებნა')
            ),

            wp.element.createElement('div', { style: { marginTop: '3em' } },
                wp.element.createElement('h3', null, '📋 ყველა ავტომობილი'),

                wp.element.createElement('input', {
                    type: 'text', className: 'form-control', placeholder: 'ძიება VIN...',
                    value: search, onChange: (e) => { setSearch(e.target.value); setCurrentPage(1); },
                    style: { marginBottom: '10px', maxWidth: '400px' }
                }),

                wp.element.createElement('div', { className: 'table-responsive shadow-sm rounded' },
                    wp.element.createElement('table', { className: 'table table-striped table-hover table-sm mb-0' },
                        wp.element.createElement('thead', { className: 'thead-light' },
                            wp.element.createElement('tr', null,
                                wp.element.createElement('th', { className: 'text-start px-2 py-1' }, 'VIN'),
                                wp.element.createElement('th', { className: 'text-start px-2 py-1' }, 'Actions')
                            )
                        ),
                        wp.element.createElement('tbody', null,
                            currentItems.map((v, i) => wp.element.createElement('tr', { key: i },
                                wp.element.createElement('td', { className: 'text-start align-middle px-2 py-1' }, v.vin),
                                wp.element.createElement('td', { className: 'text-start align-middle px-2 py-1' },
                                    wp.element.createElement('a', {
                                        href: v.permalink,
                                        className: 'btn btn-sm btn-outline-primary',
                                        target: '_blank', rel: 'noopener noreferrer'
                                    }, 'ნახე პროდუქტი')
                                )
                            ))
                        )
                    )
                ),

                wp.element.createElement('div', { className: 'd-flex justify-content-between align-items-center mt-2' },
                    wp.element.createElement('span', null, `გვერდი ${currentPage} / ${totalPages}`),
                    wp.element.createElement('div', null,
                        wp.element.createElement('button', {
                            className: 'btn btn-sm btn-secondary me-2',
                            onClick: () => setCurrentPage(p => Math.max(p - 1, 1)),
                            disabled: currentPage === 1
                        }, '◀️ წინა'),
                        wp.element.createElement('button', {
                            className: 'btn btn-sm btn-secondary',
                            onClick: () => setCurrentPage(p => Math.min(p + 1, totalPages)),
                            disabled: currentPage === totalPages
                        }, 'შემდეგი ▶️')
                    )
                )
            )
        );
    }

    wp.element.render(
        wp.element.createElement(VehicleImporterApp),
        document.getElementById('vehicle-import-ui')
    );
})(window.wp);
