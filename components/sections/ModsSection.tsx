import React, { useEffect, useState } from 'react';
import http, { httpErrorToHuman } from '@/api/http';
import { ServerContext } from '@/state/server';
import PageContentBlock from '@/components/elements/PageContentBlock';
import { usePermissions } from '@/plugins/usePermissions';

interface Provider {
    key: string;
    label: string;
}

interface Mod {
    id: string;
    name: string;
    summary: string;
    iconUrl: string | null;
    pageUrl: string | null;
    downloads: number;
    provider: string;
}

interface Version {
    id: string;
    name: string;
    gameVersion: string | null;
    loader: string | null;
}

interface InstalledMod {
    path: string;
    provider: string | null;
    project_id: string | null;
    project_name: string;
    version_id: string | null;
    version_name: string;
    icon_url: string | null;
    size: number | null;
    recognized: boolean;
    reason?: string;
}

const loaders = [
    { key: '', label: 'Any loader' },
    { key: 'fabric', label: 'Fabric' },
    { key: 'forge', label: 'Forge' },
    { key: 'neoforge', label: 'NeoForge' },
    { key: 'quilt', label: 'Quilt' },
];

const compact = (value: number): string => {
    if (value >= 1_000_000) return `${(value / 1_000_000).toFixed(1)}M`;
    if (value >= 1_000) return `${(value / 1_000).toFixed(1)}k`;

    return String(value);
};

const ModsSection = () => {
    const uuid = ServerContext.useStoreState((state) => state.server.data!.uuid);
    const [canCreateFiles, canReadFiles] = usePermissions(['file.create', 'file.read']);
    const base = `/api/client/extensions/modpacks/servers/${uuid}`;

    const [providers, setProviders] = useState<Provider[]>([]);
    const [provider, setProvider] = useState<string>('');
    const [query, setQuery] = useState<string>('');
    const [debounced, setDebounced] = useState<string>('');
    const [loader, setLoader] = useState<string>('fabric');
    const [minecraftVersion, setMinecraftVersion] = useState<string>('1.21.1');

    const [mods, setMods] = useState<Mod[]>([]);
    const [loadingMods, setLoadingMods] = useState<boolean>(true);
    const [selected, setSelected] = useState<Mod | null>(null);
    const [versions, setVersions] = useState<Version[]>([]);
    const [loadingVersions, setLoadingVersions] = useState<boolean>(false);
    const [versionId, setVersionId] = useState<string>('');
    const [installing, setInstalling] = useState<boolean>(false);
    const [installed, setInstalled] = useState<InstalledMod[]>([]);
    const [loadingInstalled, setLoadingInstalled] = useState<boolean>(false);
    const [error, setError] = useState<string | null>(null);
    const [notice, setNotice] = useState<string | null>(null);

    const loadInstalled = () => {
        if (!canReadFiles) return;

        setLoadingInstalled(true);

        http.get(`${base}/mods/installed`)
            .then(({ data }) => setInstalled(data.data))
            .catch((e) => setError(httpErrorToHuman(e)))
            .then(() => setLoadingInstalled(false));
    };

    useEffect(() => {
        http.get(`${base}/mods/providers`)
            .then(({ data }) => {
                setProviders(data.data);
                setProvider((current) => current || data.data[0]?.key || '');
            })
            .catch((e) => setError(httpErrorToHuman(e)));
    }, [base]);

    useEffect(() => {
        loadInstalled();
    }, [base, canReadFiles]);

    useEffect(() => {
        const timer = setTimeout(() => setDebounced(query), 400);

        return () => clearTimeout(timer);
    }, [query]);

    useEffect(() => {
        if (!provider) return;

        let stale = false;

        setLoadingMods(true);
        setError(null);

        http.get(`${base}/mods`, {
            params: {
                provider,
                query: debounced || undefined,
                loader: loader || undefined,
                minecraftVersion: minecraftVersion || undefined,
            },
        })
            .then(({ data }) => {
                if (!stale) setMods(data.data);
            })
            .catch((e) => {
                if (stale) return;
                setMods([]);
                setError(httpErrorToHuman(e));
            })
            .then(() => {
                if (!stale) setLoadingMods(false);
            });

        return () => {
            stale = true;
        };
    }, [base, provider, debounced, loader, minecraftVersion]);

    useEffect(() => {
        if (!selected) {
            setVersions([]);
            setVersionId('');
            return;
        }

        let stale = false;

        setLoadingVersions(true);
        setVersions([]);
        setVersionId('');

        http.get(`${base}/mods/${selected.id}/versions`, {
            params: {
                provider: selected.provider,
                loader: loader || undefined,
                minecraftVersion: minecraftVersion || undefined,
            },
        })
            .then(({ data }) => {
                if (stale) return;
                setVersions(data.data);
                setVersionId(data.data[0]?.id ?? '');
            })
            .catch((e) => {
                if (!stale) setError(httpErrorToHuman(e));
            })
            .then(() => {
                if (!stale) setLoadingVersions(false);
            });

        return () => {
            stale = true;
        };
    }, [base, selected, loader, minecraftVersion]);

    const install = () => {
        if (!selected || !versionId) return;

        setInstalling(true);
        setError(null);
        setNotice(null);

        http.post(`${base}/mods/install`, {
            provider: selected.provider,
            mod: selected.id,
            version: versionId,
        })
            .then(({ data }) => {
                const filename = data?.data?.filename;
                setNotice(filename ? `${filename} was installed into the mods folder.` : 'Mod installed.');
                loadInstalled();
            })
            .catch((e) => setError(httpErrorToHuman(e)))
            .then(() => setInstalling(false));
    };

    const field = 'rounded border border-neutral-500 bg-neutral-600 p-2 text-sm text-neutral-200';
    const installedLabel = (mod: InstalledMod): string => {
        if (mod.recognized) return `${mod.provider} - ${mod.version_name}`;
        if (mod.reason === 'pending_scan') return `${mod.version_name} - waiting to scan`;
        if (mod.reason === 'too_large') return `${mod.version_name} - too large to identify`;

        return `${mod.version_name} - not recognized`;
    };

    return (
        <PageContentBlock title={'Mods'}>
            {error && (
                <div className={'mb-4 rounded bg-red-500 p-4 text-sm text-red-50'} role={'alert'}>
                    {error}
                </div>
            )}

            {notice && (
                <div className={'mb-4 rounded bg-green-500 p-4 text-sm text-green-50'} role={'status'}>
                    {notice}
                </div>
            )}

            {!canCreateFiles && (
                <div className={'mb-4 rounded bg-yellow-500 p-4 text-sm text-yellow-900'}>
                    You can browse mods, but installing one needs the <code>file.create</code> permission.
                </div>
            )}

            <div className={'mb-4 rounded bg-neutral-700 p-4'}>
                <div className={'mb-3 flex flex-wrap items-center justify-between gap-3'}>
                    <h2 className={'text-base font-medium text-neutral-100'}>Installed mods</h2>
                    <button
                        type={'button'}
                        onClick={loadInstalled}
                        disabled={!canReadFiles || loadingInstalled}
                        className={'rounded bg-neutral-600 px-3 py-2 text-sm text-neutral-200 disabled:opacity-50'}
                    >
                        {loadingInstalled ? 'Scanning...' : 'Refresh'}
                    </button>
                </div>

                {!canReadFiles ? (
                    <p className={'text-sm text-neutral-400'}>
                        Listing installed mods needs the <code>file.read</code> permission.
                    </p>
                ) : loadingInstalled && installed.length === 0 ? (
                    <p className={'text-sm text-neutral-400'}>Scanning the mods folder...</p>
                ) : installed.length === 0 ? (
                    <p className={'text-sm text-neutral-400'}>No jar files were found in the mods folder.</p>
                ) : (
                    <div className={'grid gap-2 md:grid-cols-2'}>
                        {installed.map((mod) => (
                            <div key={mod.path} className={'flex items-center gap-3 rounded bg-neutral-800 p-3'}>
                                {mod.icon_url ? (
                                    <img
                                        src={mod.icon_url}
                                        alt={''}
                                        className={'h-10 w-10 flex-shrink-0 rounded object-cover'}
                                    />
                                ) : (
                                    <div className={'h-10 w-10 flex-shrink-0 rounded bg-neutral-600'} />
                                )}

                                <div className={'min-w-0 flex-1'}>
                                    <p className={'truncate text-sm font-medium text-neutral-100'}>
                                        {mod.project_name}
                                    </p>
                                    <p className={'truncate text-xs text-neutral-400'}>
                                        {installedLabel(mod)}
                                    </p>
                                </div>
                            </div>
                        ))}
                    </div>
                )}
            </div>

            <div className={'mb-4 flex flex-wrap items-center gap-3'}>
                <select
                    value={provider}
                    onChange={(e) => {
                        setProvider(e.currentTarget.value);
                        setSelected(null);
                    }}
                    className={field}
                    aria-label={'Mod provider'}
                >
                    {providers.map((p) => (
                        <option key={p.key} value={p.key}>
                            {p.label}
                        </option>
                    ))}
                </select>

                <select
                    value={loader}
                    onChange={(e) => {
                        setLoader(e.currentTarget.value);
                        setSelected(null);
                    }}
                    className={field}
                    aria-label={'Mod loader'}
                >
                    {loaders.map((entry) => (
                        <option key={entry.key} value={entry.key}>
                            {entry.label}
                        </option>
                    ))}
                </select>

                <input
                    value={minecraftVersion}
                    onChange={(e) => {
                        setMinecraftVersion(e.currentTarget.value);
                        setSelected(null);
                    }}
                    placeholder={'Minecraft version'}
                    className={`${field} w-36 placeholder-neutral-400`}
                    aria-label={'Minecraft version'}
                />

                <input
                    type={'search'}
                    value={query}
                    onChange={(e) => setQuery(e.currentTarget.value)}
                    placeholder={'Search mods...'}
                    className={
                        'min-w-[220px] flex-1 rounded border border-neutral-500 bg-neutral-600 p-2 text-sm ' +
                        'text-neutral-200 placeholder-neutral-400'
                    }
                    aria-label={'Search mods'}
                />
            </div>

            {loadingMods ? (
                <p className={'py-8 text-center text-sm text-neutral-400'}>Loading mods...</p>
            ) : mods.length === 0 ? (
                <p className={'py-8 text-center text-sm text-neutral-400'}>
                    {debounced ? `No mods match "${debounced}".` : 'No mods returned by this provider.'}
                </p>
            ) : (
                <div className={'grid gap-2'}>
                    {mods.map((mod) => {
                        const isSelected = selected?.id === mod.id && selected?.provider === mod.provider;

                        return (
                            <div
                                key={`${mod.provider}:${mod.id}`}
                                className={`rounded bg-neutral-700 p-4 ${isSelected ? 'ring-2 ring-primary-400' : ''}`}
                            >
                                <div className={'flex items-start gap-4'}>
                                    {mod.iconUrl && (
                                        <img
                                            src={mod.iconUrl}
                                            alt={''}
                                            className={'h-12 w-12 flex-shrink-0 rounded object-cover'}
                                        />
                                    )}

                                    <div className={'min-w-0 flex-1'}>
                                        <p className={'truncate font-medium text-neutral-100'}>{mod.name}</p>
                                        <p className={'text-sm text-neutral-400'}>{mod.summary}</p>
                                        <p className={'mt-1 text-xs text-neutral-500'}>
                                            {compact(mod.downloads)} downloads
                                            {mod.pageUrl && (
                                                <>
                                                    {' - '}
                                                    <a
                                                        href={mod.pageUrl}
                                                        target={'_blank'}
                                                        rel={'noreferrer noopener'}
                                                        className={'text-primary-400 hover:underline'}
                                                    >
                                                        View page
                                                    </a>
                                                </>
                                            )}
                                        </p>
                                    </div>

                                    <button
                                        type={'button'}
                                        onClick={() => setSelected(isSelected ? null : mod)}
                                        className={'rounded bg-neutral-600 px-3 py-2 text-sm text-neutral-200'}
                                    >
                                        {isSelected ? 'Cancel' : 'Select'}
                                    </button>
                                </div>

                                {isSelected && (
                                    <div className={'mt-4 border-t border-neutral-600 pt-4'}>
                                        {loadingVersions ? (
                                            <p className={'text-sm text-neutral-400'}>Loading versions...</p>
                                        ) : versions.length === 0 ? (
                                            <p className={'text-sm text-neutral-400'}>
                                                This mod has no matching downloadable versions.
                                            </p>
                                        ) : (
                                            <div className={'flex flex-wrap items-center gap-3'}>
                                                <select
                                                    value={versionId}
                                                    onChange={(e) => setVersionId(e.currentTarget.value)}
                                                    className={field}
                                                    aria-label={'Version'}
                                                >
                                                    {versions.map((v) => (
                                                        <option key={v.id} value={v.id}>
                                                            {v.name}
                                                            {v.gameVersion ? ` - MC ${v.gameVersion}` : ''}
                                                            {v.loader ? ` (${v.loader})` : ''}
                                                        </option>
                                                    ))}
                                                </select>

                                                <button
                                                    type={'button'}
                                                    disabled={!canCreateFiles || !versionId || installing}
                                                    onClick={install}
                                                    className={
                                                        'rounded bg-primary-500 px-4 py-2 text-sm text-primary-50 ' +
                                                        'disabled:opacity-50'
                                                    }
                                                >
                                                    {installing ? 'Installing...' : 'Install'}
                                                </button>
                                            </div>
                                        )}
                                    </div>
                                )}
                            </div>
                        );
                    })}
                </div>
            )}
        </PageContentBlock>
    );
};

export default ModsSection;
