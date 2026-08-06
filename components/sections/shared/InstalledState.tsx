import React, { useEffect, useState } from 'react';
import http from '@/api/http';

/*
 * What the extension last installed on this server, shared by the Versions
 * and Modpacks tabs rather than duplicated in each — either tab can be the
 * one that installed most recently, and both want to show the same banner
 * for it.
 *
 * There is deliberately no "provider" or "software" branching in how this
 * renders: a modpack install and a software install are just two shapes of
 * the same record, told apart by `type`, the same way ModpacksSection never
 * branches on which provider a pack came from.
 */

export interface InstalledState {
    type: 'software' | 'modpack';
    installedAt: string;
    software?: string;
    softwareLabel?: string;
    provider?: string;
    providerLabel?: string;
    packId?: string;
    packName?: string | null;
    versionId?: string;
    versionName?: string | null;
    minecraftVersion?: string | null;
    build?: string | null;
    loader?: string | null;
    loaderVersion?: string | null;
}

/**
 * Fetches `/state` once per mount. Each tab calls this independently — the
 * 300s HTTP-layer cache the providers use does not apply here since this
 * hits the panel's own endpoint, not a proxied provider, so a fresh value is
 * cheap and there is no reason to share a store across tabs that are never
 * both mounted at once.
 */
export const useInstalledState = (base: string) => {
    const [state, setState] = useState<InstalledState | null>(null);
    const [loading, setLoading] = useState<boolean>(true);
    // Bumped after a successful install so the effect below re-fetches; base
    // alone never changes within a tab's lifetime, so nothing else would.
    const [generation, setGeneration] = useState<number>(0);

    useEffect(() => {
        let stale = false;
        setLoading(true);

        http.get(`${base}/state`)
            .then(({ data }) => {
                if (!stale) setState(data.data);
            })
            .catch(() => {
                // Informational only — a failure here should not block or
                // clutter a tab whose real job is installing something.
                if (!stale) setState(null);
            })
            .then(() => {
                if (!stale) setLoading(false);
            });

        return () => {
            stale = true;
        };
    }, [base, generation]);

    const refresh = () => setGeneration((g) => g + 1);

    return { state, loading, refresh };
};

// Local bindings avoid the bare name `version`: interpolated as `$` + a
// literal `!{version}` token, it would be one of the strings Blueprint
// substitutes across every shipped file before the build, and would come out
// as this extension's own version number rather than the value computed
// here — the same trap CLAUDE.md documents for a bare `!{version}` in JSX.
const describe = (state: InstalledState): string => {
    if (state.type === 'software') {
        const label = state.softwareLabel ?? state.software ?? 'Unknown software';
        const versionSuffix = state.minecraftVersion ? ` ${state.minecraftVersion}` : '';
        const build = state.build ? ` (build ${state.build})` : '';

        return `${label}${versionSuffix}${build}`;
    }

    const pack = state.packName ?? `pack ${state.packId ?? ''}`.trim();
    const versionSuffix = state.versionName ? ` (${state.versionName})` : '';
    const loader = state.loader ? ` — ${state.loader}${state.loaderVersion ? ` ${state.loaderVersion}` : ''}` : '';

    return `${pack}${versionSuffix}${loader}`;
};

/** Renders nothing while loading or when nothing has ever been installed. */
export const InstalledStateBanner = ({ state, loading }: { state: InstalledState | null; loading: boolean }) => {
    if (loading || !state) {
        return null;
    }

    return (
        <div className={'mb-4 rounded bg-neutral-700 p-3 text-sm text-neutral-300'}>
            <span className={'text-neutral-500'}>{state.type === 'software' ? 'Currently running: ' : 'Installed: '}</span>
            <span className={'font-medium text-neutral-100'}>{describe(state)}</span>
        </div>
    );
};
