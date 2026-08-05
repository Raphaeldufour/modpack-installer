import React, { useEffect, useState } from 'react';
import http, { httpErrorToHuman } from '@/api/http';
import { ServerContext } from '@/state/server';
import PageContentBlock from '@/components/elements/PageContentBlock';
import { usePermissions } from '@/plugins/usePermissions';

/*
 * The Modpacks tab.
 *
 * Everything here is provider-agnostic on purpose: the provider list comes from
 * the API, and a pack carries the provider it came from. There is deliberately
 * no `if (provider === 'curseforge')` anywhere in this file — normalising that
 * away is the whole job of Pack and Version on the PHP side, and the moment the
 * frontend starts branching, adding a provider stops being a backend-only
 * change.
 */

interface Provider {
    key: string;
    label: string;
}

interface Pack {
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

const compact = (value: number): string => {
    if (value >= 1_000_000) return `${(value / 1_000_000).toFixed(1)}M`;
    if (value >= 1_000) return `${(value / 1_000).toFixed(1)}k`;

    return String(value);
};

const ModpacksSection = () => {
    const uuid = ServerContext.useStoreState((state) => state.server.data!.uuid);

    // Installing wipes the filesystem and rewrites the startup command, so the
    // API demands both permissions. Mirror that here rather than letting the
    // user fill in the whole form and collect a 403 at the end.
    const [canUpdateStartup, canDeleteFiles] = usePermissions(['startup.update', 'file.delete']);
    const canInstall = canUpdateStartup && canDeleteFiles;

    const base = `/api/client/servers/${uuid}/modpacks`;

    const [providers, setProviders] = useState<Provider[]>([]);
    const [provider, setProvider] = useState<string>('');

    const [query, setQuery] = useState<string>('');
    const [debounced, setDebounced] = useState<string>('');

    const [packs, setPacks] = useState<Pack[]>([]);
    const [loadingPacks, setLoadingPacks] = useState<boolean>(true);

    const [selected, setSelected] = useState<Pack | null>(null);
    const [versions, setVersions] = useState<Version[]>([]);
    const [loadingVersions, setLoadingVersions] = useState<boolean>(false);
    const [versionId, setVersionId] = useState<string>('');

    const [wipe, setWipe] = useState<boolean>(true);
    const [confirming, setConfirming] = useState<boolean>(false);
    const [installing, setInstalling] = useState<boolean>(false);

    const [error, setError] = useState<string | null>(null);
    const [notice, setNotice] = useState<string | null>(null);

    useEffect(() => {
        http.get(`${base}/providers`)
            .then(({ data }) => {
                setProviders(data.data);
                setProvider((current) => current || data.data[0]?.key || '');
            })
            .catch((e) => setError(httpErrorToHuman(e)));
    }, [base]);

    // Typing straight through to the provider would burn its rate limit; the
    // 300s server-side cache does nothing for keystrokes that each differ.
    useEffect(() => {
        const timer = setTimeout(() => setDebounced(query), 400);

        return () => clearTimeout(timer);
    }, [query]);

    useEffect(() => {
        if (!provider) return;

        // Switching providers quickly can land responses out of order, which
        // shows results under the wrong provider. Ignore anything that comes
        // back after we have moved on.
        let stale = false;

        setLoadingPacks(true);
        setError(null);

        http.get(`${base}/packs`, { params: { provider, query: debounced || undefined } })
            .then(({ data }) => {
                if (!stale) setPacks(data.data);
            })
            .catch((e) => {
                if (stale) return;
                setPacks([]);
                setError(httpErrorToHuman(e));
            })
            .then(() => {
                if (!stale) setLoadingPacks(false);
            });

        return () => {
            stale = true;
        };
    }, [base, provider, debounced]);

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

        http.get(`${base}/packs/${selected.id}/versions`, { params: { provider: selected.provider } })
            .then(({ data }) => {
                if (stale) return;
                setVersions(data.data);
                // Providers return newest first, so the top entry is the sane
                // default and saves a click in the common case.
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
    }, [base, selected]);

    const install = () => {
        if (!selected || !versionId) return;

        setInstalling(true);
        setError(null);
        setNotice(null);

        http.post(`${base}/install`, {
            provider: selected.provider,
            pack: selected.id,
            // The API field is `version`; the local name only differs because
            // `!{version}` is a Blueprint placeholder. Written unescaped in the
            // JSX below it would be replaced by the extension version at build
            // time, producing `value=0.1.0` and a syntax error.
            version: versionId,
            wipe,
        })
            .then(() => {
                setConfirming(false);
                setNotice(
                    'Installation started. Progress is streamed to the server console — the server ' +
                        'will be unavailable until it finishes.',
                );
            })
            .catch((e) => {
                setConfirming(false);
                setError(httpErrorToHuman(e));
            })
            .then(() => setInstalling(false));
    };

    const selectedVersion = versions.find((v) => v.id === versionId) || null;

    return (
        <PageContentBlock title={'Modpacks'}>
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

            {!canInstall && (
                <div className={'mb-4 rounded bg-yellow-500 p-4 text-sm text-yellow-900'}>
                    You can browse modpacks, but installing one needs both the <code>startup.update</code> and{' '}
                    <code>file.delete</code> permissions.
                </div>
            )}

            <div className={'mb-4 flex flex-wrap items-center gap-3'}>
                <select
                    value={provider}
                    onChange={(e) => {
                        setProvider(e.currentTarget.value);
                        setSelected(null);
                    }}
                    className={'rounded border border-neutral-500 bg-neutral-600 p-2 text-sm text-neutral-200'}
                    aria-label={'Modpack provider'}
                >
                    {providers.map((p) => (
                        <option key={p.key} value={p.key}>
                            {p.label}
                        </option>
                    ))}
                </select>

                <input
                    type={'search'}
                    value={query}
                    onChange={(e) => setQuery(e.currentTarget.value)}
                    placeholder={'Search modpacks…'}
                    className={
                        'flex-1 rounded border border-neutral-500 bg-neutral-600 p-2 text-sm text-neutral-200 ' +
                        'placeholder-neutral-400'
                    }
                    aria-label={'Search modpacks'}
                />
            </div>

            {loadingPacks ? (
                <p className={'py-8 text-center text-sm text-neutral-400'}>Loading modpacks…</p>
            ) : packs.length === 0 ? (
                <p className={'py-8 text-center text-sm text-neutral-400'}>
                    {debounced ? `No modpacks match “${debounced}”.` : 'No modpacks returned by this provider.'}
                </p>
            ) : (
                <div className={'grid gap-2'}>
                    {packs.map((pack) => {
                        const isSelected = selected?.id === pack.id && selected?.provider === pack.provider;

                        return (
                            <div
                                key={`${pack.provider}:${pack.id}`}
                                className={`rounded bg-neutral-700 p-4 ${isSelected ? 'ring-2 ring-primary-400' : ''}`}
                            >
                                <div className={'flex items-start gap-4'}>
                                    {pack.iconUrl && (
                                        <img
                                            src={pack.iconUrl}
                                            alt={''}
                                            className={'h-12 w-12 flex-shrink-0 rounded object-cover'}
                                        />
                                    )}

                                    <div className={'min-w-0 flex-1'}>
                                        <p className={'truncate font-medium text-neutral-100'}>{pack.name}</p>
                                        <p className={'text-sm text-neutral-400'}>{pack.summary}</p>
                                        <p className={'mt-1 text-xs text-neutral-500'}>
                                            {compact(pack.downloads)} downloads
                                            {pack.pageUrl && (
                                                <>
                                                    {' · '}
                                                    <a
                                                        href={pack.pageUrl}
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
                                        onClick={() => setSelected(isSelected ? null : pack)}
                                        className={'rounded bg-neutral-600 px-3 py-2 text-sm text-neutral-200'}
                                    >
                                        {isSelected ? 'Cancel' : 'Select'}
                                    </button>
                                </div>

                                {isSelected && (
                                    <div className={'mt-4 border-t border-neutral-600 pt-4'}>
                                        {loadingVersions ? (
                                            <p className={'text-sm text-neutral-400'}>Loading versions…</p>
                                        ) : versions.length === 0 ? (
                                            <p className={'text-sm text-neutral-400'}>
                                                This pack has no installable versions.
                                            </p>
                                        ) : (
                                            <>
                                                <div className={'flex flex-wrap items-center gap-3'}>
                                                    <select
                                                        value={versionId}
                                                        onChange={(e) => setVersionId(e.currentTarget.value)}
                                                        className={
                                                            'rounded border border-neutral-500 bg-neutral-600 p-2 ' +
                                                            'text-sm text-neutral-200'
                                                        }
                                                        aria-label={'Version'}
                                                    >
                                                        {versions.map((v) => (
                                                            <option key={v.id} value={v.id}>
                                                                {v.name}
                                                                {v.gameVersion ? ` — MC ${v.gameVersion}` : ''}
                                                                {v.loader ? ` (${v.loader})` : ''}
                                                            </option>
                                                        ))}
                                                    </select>

                                                    <label className={'flex items-center gap-2 text-sm text-neutral-300'}>
                                                        <input
                                                            type={'checkbox'}
                                                            checked={wipe}
                                                            onChange={(e) => setWipe(e.currentTarget.checked)}
                                                        />
                                                        Wipe existing files
                                                    </label>

                                                    <button
                                                        type={'button'}
                                                        disabled={!canInstall || !versionId || installing}
                                                        onClick={() => setConfirming(true)}
                                                        className={
                                                            'rounded bg-primary-500 px-4 py-2 text-sm text-primary-50 ' +
                                                            'disabled:opacity-50'
                                                        }
                                                    >
                                                        {installing ? 'Starting…' : 'Install'}
                                                    </button>
                                                </div>

                                                <p className={'mt-2 text-xs text-neutral-500'}>
                                                    {wipe
                                                        ? 'Server files will be cleared before installing. Worlds are preserved.'
                                                        : 'Existing files will be kept. Leftovers from a previous pack can break the new one.'}
                                                </p>
                                            </>
                                        )}
                                    </div>
                                )}
                            </div>
                        );
                    })}
                </div>
            )}

            {/*
              * Installing is destructive and cannot be undone from here, so it
              * gets an explicit confirmation naming the pack and the version.
              */}
            {confirming && selected && (
                <div className={'fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50 p-4'}>
                    <div className={'w-full max-w-md rounded bg-neutral-700 p-6'}>
                        <h2 className={'mb-2 text-lg text-neutral-100'}>Install this modpack?</h2>
                        <p className={'mb-4 text-sm text-neutral-300'}>
                            <strong>{selected.name}</strong>
                            {selectedVersion ? ` (${selectedVersion.name})` : ''} will be installed. The server will be
                            stopped and reinstalled.
                            {wipe
                                ? ' Existing server files will be deleted, except worlds and server.properties.'
                                : ' Existing files will be left in place.'}
                        </p>

                        <div className={'flex justify-end gap-3'}>
                            <button
                                type={'button'}
                                onClick={() => setConfirming(false)}
                                disabled={installing}
                                className={'rounded bg-neutral-600 px-4 py-2 text-sm text-neutral-200'}
                            >
                                Cancel
                            </button>
                            <button
                                type={'button'}
                                onClick={install}
                                disabled={installing}
                                className={'rounded bg-red-500 px-4 py-2 text-sm text-red-50 disabled:opacity-50'}
                            >
                                {installing ? 'Starting…' : 'Yes, install'}
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </PageContentBlock>
    );
};

export default ModpacksSection;
