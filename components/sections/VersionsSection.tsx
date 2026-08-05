import React, { useEffect, useState } from 'react';
import http, { httpErrorToHuman } from '@/api/http';
import { ServerContext } from '@/state/server';
import PageContentBlock from '@/components/elements/PageContentBlock';
import { usePermissions } from '@/plugins/usePermissions';

/*
 * The Versions tab: swap the server jar for another build of another server
 * software, without touching worlds, configs, plugins or mods.
 *
 * Software-agnostic in the same way the Modpacks tab is provider-agnostic. The
 * list comes from the API and every entry is treated identically, including the
 * ones that have no build concept — Vanilla publishes one jar per version, and
 * an empty build list is the signal to hide that selector rather than something
 * to special-case by name.
 *
 * Note the local names: `mcVersion`, not `version`. Blueprint substitutes
 * !{version} across every file before the build, so `value=!{version}` would be
 * rewritten to the extension's version number and fail to parse.
 */

interface Software {
    key: string;
    label: string;
}

interface BuildEntry {
    id: string;
    name: string;
    stable: boolean;
}

const VersionsSection = () => {
    const uuid = ServerContext.useStoreState((state) => state.server.data!.uuid);

    const [canUpdateStartup, canDeleteFiles] = usePermissions(['startup.update', 'file.delete']);
    const canInstall = canUpdateStartup && canDeleteFiles;

    const base = `/api/client/extensions/modpacks/servers/${uuid}`;

    const [software, setSoftware] = useState<Software[]>([]);
    const [selected, setSelected] = useState<string>('');

    const [mcVersions, setMcVersions] = useState<string[]>([]);
    const [mcVersion, setMcVersion] = useState<string>('');
    const [loadingVersions, setLoadingVersions] = useState<boolean>(true);

    const [builds, setBuilds] = useState<BuildEntry[]>([]);
    const [buildId, setBuildId] = useState<string>('');
    const [loadingBuilds, setLoadingBuilds] = useState<boolean>(false);

    const [confirming, setConfirming] = useState<boolean>(false);
    const [installing, setInstalling] = useState<boolean>(false);

    const [error, setError] = useState<string | null>(null);
    const [notice, setNotice] = useState<string | null>(null);

    useEffect(() => {
        http.get(`${base}/software`)
            .then(({ data }) => {
                setSoftware(data.data);
                setSelected((current) => current || data.data[0]?.key || '');
            })
            .catch((e) => setError(httpErrorToHuman(e)));
    }, [base]);

    useEffect(() => {
        if (!selected) return;

        let stale = false;

        setLoadingVersions(true);
        setError(null);
        setMcVersions([]);
        setMcVersion('');

        http.get(`${base}/software/versions`, { params: { software: selected } })
            .then(({ data }) => {
                if (stale) return;
                setMcVersions(data.data);
                setMcVersion(data.data[0] ?? '');
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

    useEffect(() => {
        if (!selected || !mcVersion) {
            setBuilds([]);
            setBuildId('');
            return;
        }

        let stale = false;

        setLoadingBuilds(true);
        setBuilds([]);
        setBuildId('');

        http.get(`${base}/software/versions/${encodeURIComponent(mcVersion)}/builds`, {
            params: { software: selected },
        })
            .then(({ data }) => {
                if (stale) return;
                setBuilds(data.data);
                setBuildId(data.data[0]?.id ?? '');
            })
            .catch((e) => {
                if (!stale) setError(httpErrorToHuman(e));
            })
            .then(() => {
                if (!stale) setLoadingBuilds(false);
            });

        return () => {
            stale = true;
        };
    }, [base, selected, mcVersion]);

    const install = () => {
        if (!selected || !mcVersion) return;

        setInstalling(true);
        setError(null);
        setNotice(null);

        http.post(`${base}/software/install`, {
            software: selected,
            minecraftVersion: mcVersion,
            build: buildId || null,
        })
            .then(({ data }) => {
                setConfirming(false);
                const notes: string[] = data?.data?.notes ?? [];
                setNotice(
                    [`Server jar replaced. Start the server to run ${label(selected)} ${mcVersion}.`, ...notes].join(
                        ' ',
                    ),
                );
            })
            .catch((e) => {
                setConfirming(false);
                setError(httpErrorToHuman(e));
            })
            .then(() => setInstalling(false));
    };

    const label = (key: string) => software.find((s) => s.key === key)?.label ?? key;

    const field = 'rounded border border-neutral-500 bg-neutral-600 p-2 text-sm text-neutral-200';

    return (
        <PageContentBlock title={'Versions'}>
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
                    You can browse versions, but changing one needs both the <code>startup.update</code> and{' '}
                    <code>file.delete</code> permissions.
                </div>
            )}

            <div className={'mb-4 rounded bg-neutral-700 p-4'}>
                <div className={'flex flex-wrap items-end gap-4'}>
                    <div>
                        <label className={'mb-1 block text-xs uppercase text-neutral-400'}>Software</label>
                        <select
                            value={selected}
                            onChange={(e) => setSelected(e.currentTarget.value)}
                            className={field}
                            aria-label={'Server software'}
                        >
                            {software.map((s) => (
                                <option key={s.key} value={s.key}>
                                    {s.label}
                                </option>
                            ))}
                        </select>
                    </div>

                    <div>
                        <label className={'mb-1 block text-xs uppercase text-neutral-400'}>Minecraft version</label>
                        <select
                            value={mcVersion}
                            onChange={(e) => setMcVersion(e.currentTarget.value)}
                            className={field}
                            disabled={loadingVersions || mcVersions.length === 0}
                            aria-label={'Minecraft version'}
                        >
                            {loadingVersions && <option>Loading…</option>}
                            {mcVersions.map((v) => (
                                <option key={v} value={v}>
                                    {v}
                                </option>
                            ))}
                        </select>
                    </div>

                    {/* Vanilla publishes one jar per version, so an empty build
                        list is meaningful rather than a loading state. */}
                    {(loadingBuilds || builds.length > 0) && (
                        <div>
                            <label className={'mb-1 block text-xs uppercase text-neutral-400'}>Build</label>
                            <select
                                value={buildId}
                                onChange={(e) => setBuildId(e.currentTarget.value)}
                                className={field}
                                disabled={loadingBuilds}
                                aria-label={'Build'}
                            >
                                {loadingBuilds && <option>Loading…</option>}
                                {builds.map((b) => (
                                    <option key={b.id} value={b.id}>
                                        {b.name}
                                        {b.stable ? '' : ' (unstable)'}
                                    </option>
                                ))}
                            </select>
                        </div>
                    )}

                    <button
                        type={'button'}
                        disabled={!canInstall || !mcVersion || installing || loadingVersions}
                        onClick={() => setConfirming(true)}
                        className={'rounded bg-primary-500 px-4 py-2 text-sm text-primary-50 disabled:opacity-50'}
                    >
                        {installing ? 'Installing…' : 'Install'}
                    </button>
                </div>

                <p className={'mt-3 text-xs text-neutral-500'}>
                    Only the server jar and the startup command change. Worlds, configs, plugins and mods are left
                    exactly as they are.
                </p>
            </div>

            {confirming && (
                <div className={'fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50 p-4'}>
                    <div className={'w-full max-w-md rounded bg-neutral-700 p-6'}>
                        <h2 className={'mb-2 text-lg text-neutral-100'}>Change the server version?</h2>
                        <p className={'mb-4 text-sm text-neutral-300'}>
                            The server will be stopped and its jar replaced with{' '}
                            <strong>
                                {label(selected)} {mcVersion}
                                {buildId ? ` (${builds.find((b) => b.id === buildId)?.name ?? buildId})` : ''}
                            </strong>
                            . Your worlds and configuration are not touched, but a world saved by a newer Minecraft
                            version cannot be opened by an older one.
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
                                {installing ? 'Installing…' : 'Yes, change it'}
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </PageContentBlock>
    );
};

export default VersionsSection;
