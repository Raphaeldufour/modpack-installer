import React, { useEffect, useState } from 'react';
import http, { httpErrorToHuman } from '@/api/http';
import { ServerContext } from '@/state/server';
import PageContentBlock from '@/components/elements/PageContentBlock';
import { usePermissions } from '@/plugins/usePermissions';
import { InstalledStateBanner, useInstalledState } from './shared/InstalledState';

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
 * The browse grid (categories, cards, per-card version counts) follows the
 * layout mcjars.app uses. What is deliberately not copied is a per-card
 * *build* count: theirs comes from a database that has indexed every build an
 * engine has ever published, which this extension has no equivalent of. A
 * live total across every Minecraft version a software has shipped would cost
 * one upstream call per version just to render the grid — see
 * SoftwareRegistry::toArray()'s docblock. Only the Minecraft-version count is
 * shown, because every implementation already caches that as one call.
 *
 * Note the local names: `mcVersion`, not `version`. Blueprint substitutes
 * !{version} across every file before the build, so `value=!{version}` would
 * be rewritten to the extension's version number and fail to parse.
 */

interface Software {
    key: string;
    label: string;
    category: string;
    minecraftVersionCount: number | null;
}

interface BuildEntry {
    id: string;
    name: string;
    stable: boolean;
}

const CATEGORY_ORDER: { key: string; label: string }[] = [
    { key: 'recommended', label: 'Recommended' },
    { key: 'established', label: 'Established' },
    { key: 'experimental', label: 'Experimental' },
];

interface IconConfig {
    background: string;
    glyph: React.ReactNode;
}

// Small, self-contained pictograms rather than hotlinked project logos — a
// broken or rate-limited image would otherwise sit in a grid that is supposed
// to load instantly. Each evokes its software (a grass block, a paper plane, a
// woven swatch…) without reproducing any project's actual mark.
const SOFTWARE_ICONS: Record<string, IconConfig> = {
    vanilla: {
        background: '#3f6b1f',
        glyph: (
            <>
                <rect x={5} y={18} width={30} height={17} rx={2} fill={'#7b5233'} />
                <rect x={5} y={13} width={30} height={7} fill={'#5a8f2c'} />
                <rect x={9} y={7} width={5} height={5} fill={'#8fc45a'} opacity={0.8} />
                <rect x={17} y={5} width={5} height={5} fill={'#8fc45a'} opacity={0.6} />
            </>
        ),
    },
    paper: {
        background: '#eae4d6',
        glyph: (
            <>
                <path d={'M8 21 L31 9 L23 32 L18 23 Z'} fill={'#4a4230'} />
                <path d={'M8 21 L18 23 L14 27 Z'} fill={'#2b2619'} />
            </>
        ),
    },
    fabric: {
        background: '#ded3fb',
        glyph: (
            <>
                <rect x={8} y={8} width={10} height={10} fill={'#8a6fd8'} />
                <rect x={22} y={8} width={10} height={10} fill={'#a58af0'} />
                <rect x={8} y={22} width={10} height={10} fill={'#a58af0'} />
                <rect x={22} y={22} width={10} height={10} fill={'#8a6fd8'} />
            </>
        ),
    },
    purpur: {
        background: '#4c2380',
        glyph: <circle cx={20} cy={20} r={10} fill={'#b586ff'} />,
    },
    folia: {
        background: '#1c4a37',
        glyph: <path d={'M11 30 C11 16 30 11 30 11 C30 11 25 30 11 30 Z'} fill={'#4fbf88'} />,
    },
    velocity: {
        background: '#173a5e',
        glyph: <path d={'M22 6 L11 22 L18 22 L16 34 L30 15 L21 15 Z'} fill={'#5bc8f7'} />,
    },
};

const DEFAULT_ICON: IconConfig = {
    background: '#3a3a3a',
    glyph: <circle cx={20} cy={20} r={5} fill={'#9a9a9a'} />,
};

const SoftwareIcon = ({ software }: { software: string }) => {
    const config = SOFTWARE_ICONS[software] ?? DEFAULT_ICON;

    return (
        <svg viewBox={'0 0 40 40'} className={'h-10 w-10 flex-shrink-0 rounded-lg'} aria-hidden={'true'}>
            <rect width={40} height={40} rx={8} fill={config.background} />
            {config.glyph}
        </svg>
    );
};

const versionCountLabel = (count: number | null): string => {
    if (count === null) return 'Version list unavailable';

    return `${count} Minecraft version${count === 1 ? '' : 's'}`;
};

const VersionsSection = () => {
    const uuid = ServerContext.useStoreState((state) => state.server.data!.uuid);

    const [canUpdateStartup, canDeleteFiles] = usePermissions(['startup.update', 'file.delete']);
    const canInstall = canUpdateStartup && canDeleteFiles;

    const base = `/api/client/extensions/modpacks/servers/${uuid}`;

    const { state: installedState, loading: loadingInstalledState, refresh: refreshInstalledState } =
        useInstalledState(base);

    const [software, setSoftware] = useState<Software[]>([]);
    const [loadingSoftware, setLoadingSoftware] = useState<boolean>(true);
    const [selected, setSelected] = useState<string>('');
    const [defaultedSelection, setDefaultedSelection] = useState<boolean>(false);

    const [mcVersions, setMcVersions] = useState<string[]>([]);
    const [mcVersion, setMcVersion] = useState<string>('');
    const [loadingVersions, setLoadingVersions] = useState<boolean>(false);

    const [builds, setBuilds] = useState<BuildEntry[]>([]);
    const [buildId, setBuildId] = useState<string>('');
    const [loadingBuilds, setLoadingBuilds] = useState<boolean>(false);

    const [confirming, setConfirming] = useState<boolean>(false);
    const [installing, setInstalling] = useState<boolean>(false);

    // Whether the running build is still the latest one — separate from
    // InstalledStateBanner, since that banner only knows what is installed,
    // not what is current. Silent when it cannot be determined (no software
    // running yet, or the software has no build concept, like Vanilla).
    const [latestBuild, setLatestBuild] = useState<BuildEntry | null>(null);

    const [error, setError] = useState<string | null>(null);
    const [notice, setNotice] = useState<string | null>(null);

    useEffect(() => {
        setLoadingSoftware(true);

        http.get(`${base}/software`)
            .then(({ data }) => setSoftware(data.data))
            .catch((e) => setError(httpErrorToHuman(e)))
            .then(() => setLoadingSoftware(false));
    }, [base]);

    // Opens the tab already pointed at whatever is running, the same way the
    // screenshot this was modelled on has Paper's card already in focus. Only
    // ever fires once — a user who has since clicked a different card is not
    // second-guessed by a state refresh after they install something.
    useEffect(() => {
        if (defaultedSelection || !installedState || installedState.type !== 'software' || !installedState.software) {
            return;
        }

        if (software.some((s) => s.key === installedState.software)) {
            setDefaultedSelection(true);
            // Functional form so a manual click that landed first (selected is
            // not in this effect's deps, so its closure value could be stale)
            // is never clobbered by this one-time default.
            setSelected((current) => current || installedState.software!);
        }
    }, [installedState, software, defaultedSelection]);

    useEffect(() => {
        if (
            !installedState ||
            installedState.type !== 'software' ||
            !installedState.software ||
            !installedState.minecraftVersion ||
            !installedState.build
        ) {
            setLatestBuild(null);
            return;
        }

        let stale = false;

        http.get(`${base}/software/versions/${encodeURIComponent(installedState.minecraftVersion)}/builds`, {
            params: { software: installedState.software },
        })
            .then(({ data }) => {
                if (!stale) setLatestBuild(data.data[0] ?? null);
            })
            .catch(() => {
                // Best-effort nudge only — a failed check just means no
                // warning is shown, not an error worth surfacing.
                if (!stale) setLatestBuild(null);
            });

        return () => {
            stale = true;
        };
    }, [base, installedState]);

    useEffect(() => {
        if (!selected) {
            setMcVersions([]);
            setMcVersion('');
            return;
        }

        let stale = false;

        setLoadingVersions(true);
        setError(null);
        setMcVersions([]);
        setMcVersion('');

        http.get(`${base}/software/versions`, { params: { software: selected } })
            .then(({ data }) => {
                if (stale) return;

                setMcVersions(data.data);

                // Preselect the running Minecraft version when this card is the
                // one already installed, instead of always defaulting to the
                // newest — the point of opening on the running software is to
                // show its own state, not to nudge an upgrade before asked.
                const running =
                    installedState?.type === 'software' &&
                    installedState.software === selected &&
                    installedState.minecraftVersion &&
                    data.data.includes(installedState.minecraftVersion)
                        ? installedState.minecraftVersion
                        : null;

                setMcVersion(running ?? data.data[0] ?? '');
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
        // installedState is intentionally excluded: it is only consulted for
        // the one preselection above, at the moment a software is chosen, not
        // re-applied every time a background refresh gives a new object.
        // eslint-disable-next-line react-hooks/exhaustive-deps
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
                refreshInstalledState();
            })
            .catch((e) => {
                setConfirming(false);
                setError(httpErrorToHuman(e));
            })
            .then(() => setInstalling(false));
    };

    const label = (key: string) => software.find((s) => s.key === key)?.label ?? key;

    const runningKey = installedState?.type === 'software' ? installedState.software ?? null : null;
    const isOutdated = Boolean(latestBuild && installedState?.build && latestBuild.id !== installedState.build);

    const field = 'rounded border border-neutral-500 bg-neutral-600 p-2 text-sm text-neutral-200';

    const grouped = CATEGORY_ORDER.map((category) => ({
        ...category,
        items: software.filter((s) => s.category === category.key),
    })).filter((category) => category.items.length > 0);

    return (
        <PageContentBlock title={'Versions'}>
            <InstalledStateBanner state={installedState} loading={loadingInstalledState} />

            {isOutdated && latestBuild && installedState && (
                <div className={'mb-4 rounded bg-yellow-500 p-4 text-sm text-yellow-900'} role={'status'}>
                    Your server is currently running an outdated version of{' '}
                    <strong>
                        {installedState.softwareLabel ?? label(installedState.software ?? '')}{' '}
                        {installedState.minecraftVersion}
                    </strong>
                    . The latest build is {latestBuild.name}.
                </div>
            )}

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

            {loadingSoftware ? (
                <p className={'py-8 text-center text-sm text-neutral-400'}>Loading server software...</p>
            ) : (
                grouped.map((category) => (
                    <div key={category.key} className={'mb-6'}>
                        <h3 className={'mb-2 text-xs font-semibold uppercase tracking-wide text-neutral-500'}>
                            {category.label}
                        </h3>
                        <div className={'grid gap-3 sm:grid-cols-2 lg:grid-cols-3'}>
                            {category.items.map((s) => {
                                const isSelected = selected === s.key;
                                const isRunning = runningKey === s.key;

                                return (
                                    <button
                                        key={s.key}
                                        type={'button'}
                                        onClick={() => setSelected(isSelected ? '' : s.key)}
                                        aria-pressed={isSelected}
                                        className={
                                            'flex items-center gap-3 rounded-lg border bg-neutral-700 p-3 text-left transition ' +
                                            (isSelected
                                                ? 'border-primary-400 ring-1 ring-primary-400'
                                                : isRunning
                                                  ? 'border-l-4 border-l-primary-400 border-neutral-600'
                                                  : 'border-neutral-600 hover:border-neutral-500')
                                        }
                                    >
                                        <SoftwareIcon software={s.key} />
                                        <div className={'min-w-0 flex-1'}>
                                            <div className={'flex flex-wrap items-center gap-2'}>
                                                <p className={'truncate text-sm font-medium text-neutral-100'}>
                                                    {s.label}
                                                </p>
                                                {isRunning && (
                                                    <span
                                                        className={
                                                            'rounded bg-primary-500 bg-opacity-20 px-1.5 py-0.5 ' +
                                                            'text-[10px] font-semibold uppercase text-primary-400'
                                                        }
                                                    >
                                                        Running
                                                    </span>
                                                )}
                                            </div>
                                            <p className={'text-xs text-neutral-400'}>
                                                {versionCountLabel(s.minecraftVersionCount)}
                                            </p>
                                        </div>
                                    </button>
                                );
                            })}
                        </div>
                    </div>
                ))
            )}

            {selected && (
                <div className={'mb-4 rounded bg-neutral-700 p-4'}>
                    <div className={'flex flex-wrap items-end gap-4'}>
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
                            {installing ? 'Installing…' : `Install ${label(selected)}`}
                        </button>
                    </div>

                    <p className={'mt-3 text-xs text-neutral-500'}>
                        Only the server jar and the startup command change. Worlds, configs, plugins and mods are left
                        exactly as they are.
                    </p>
                </div>
            )}

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
