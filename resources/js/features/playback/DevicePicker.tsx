import { tr } from "../../i18n/runtime";
import { useLocale } from "../../i18n/LocaleProvider";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { api, json } from "../../lib/api";
import type { PlaybackDevice, PlaybackLease } from "../../lib/playback-device";
import { ErrorState, Loading } from "../../components/UI";

type DevicesPayload = {
    devices: PlaybackDevice[];
    lease: PlaybackLease | null;
};

export function DevicePicker({
    currentDeviceId,
    leaseToken,
    onClaimed,
}: {
    currentDeviceId: string | null;
    leaseToken: string | null;
    onClaimed: (lease: PlaybackLease) => void;
}) {
    useLocale();
    const client = useQueryClient();
    const query = useQuery({
        queryKey: ["playback-devices"],
        queryFn: () => api<{ data: DevicesPayload }>("/playback/devices"),
        refetchInterval: 8000,
    });
    const claim = useMutation({
        mutationFn: (deviceId: string) =>
            api<{ data: PlaybackLease }>(
                `/playback/devices/${deviceId}/claim`,
                {
                    method: "POST",
                },
            ),
        onSuccess: (result) => {
            onClaimed(result.data);
            void client.invalidateQueries({ queryKey: ["playback-devices"] });
        },
    });
    const transfer = useMutation({
        mutationFn: (targetId: string) =>
            api<{ data: PlaybackLease }>(
                `/playback/devices/${currentDeviceId}/transfer`,
                {
                    method: "POST",
                    body: json({
                        lease_token: leaseToken,
                        target_device_id: targetId,
                    }),
                },
            ),
        onSuccess: () =>
            void client.invalidateQueries({ queryKey: ["playback-devices"] }),
    });
    const revoke = useMutation({
        mutationFn: (deviceId: string) =>
            api(`/playback/devices/${deviceId}`, { method: "DELETE" }),
        onSuccess: () =>
            void client.invalidateQueries({ queryKey: ["playback-devices"] }),
    });
    const accept = useMutation({
        mutationFn: () =>
            api<{ data: PlaybackLease }>(
                `/playback/devices/${currentDeviceId}/transfer/accept`,
                { method: "POST" },
            ),
        onSuccess: (result) => {
            onClaimed(result.data);
            void client.invalidateQueries({ queryKey: ["playback-devices"] });
        },
    });
    const reject = useMutation({
        mutationFn: () =>
            api(`/playback/devices/${currentDeviceId}/transfer/reject`, {
                method: "POST",
            }),
        onSuccess: () =>
            void client.invalidateQueries({ queryKey: ["playback-devices"] }),
    });
    if (query.isPending) return <Loading />;
    if (query.error)
        return (
            <ErrorState
                error={query.error}
                retry={() => void query.refetch()}
            />
        );
    const data = query.data!.data;
    const lease = data.lease;
    const controlling =
        lease?.device_id === currentDeviceId && lease?.state === "active";
    const offerForMe =
        lease?.state === "transferring" &&
        lease.transfer_target_id === currentDeviceId;
    return (
        <section className="device-picker" aria-label={tr("devices.title")}>
            <header>
                <h3>{tr("devices.title")}</h3>
                <p className="muted">
                    {controlling
                        ? tr("devices.controlling")
                        : tr("devices.spectator")}
                </p>
            </header>
            {offerForMe && (
                <div className="device-transfer-banner" role="status">
                    <p>{tr("devices.transferOffer")}</p>
                    <div className="row">
                        <button
                            className="button"
                            onClick={() => accept.mutate()}
                            disabled={accept.isPending}
                        >
                            {tr("devices.accept")}
                        </button>
                        <button
                            className="button ghost"
                            onClick={() => reject.mutate()}
                            disabled={reject.isPending}
                        >
                            {tr("devices.reject")}
                        </button>
                    </div>
                </div>
            )}
            {lease?.state === "transferring" &&
                lease.device_id === currentDeviceId && (
                    <p className="muted" role="status">
                        {tr("devices.transferLoading")}
                    </p>
                )}
            <ul className="device-list">
                {data.devices.map((device) => {
                    const isCurrent = device.id === currentDeviceId;
                    const isActive = lease?.device_id === device.id;
                    return (
                        <li key={device.id}>
                            <div>
                                <strong>
                                    {device.name}
                                    {isCurrent
                                        ? ` · ${tr("devices.thisDevice")}`
                                        : ""}
                                </strong>
                                <p className="muted">
                                    {isActive
                                        ? tr("devices.activeLease")
                                        : tr("devices.idle")}
                                </p>
                            </div>
                            <div className="row">
                                {!isActive && (
                                    <button
                                        className="button ghost"
                                        onClick={() => claim.mutate(device.id)}
                                        disabled={claim.isPending}
                                    >
                                        {tr("devices.claim")}
                                    </button>
                                )}
                                {controlling && !isCurrent && (
                                    <button
                                        className="button ghost"
                                        onClick={() =>
                                            transfer.mutate(device.id)
                                        }
                                        disabled={
                                            transfer.isPending || !leaseToken
                                        }
                                    >
                                        {tr("devices.transfer")}
                                    </button>
                                )}
                                {!isCurrent && (
                                    <button
                                        className="button ghost"
                                        onClick={() => revoke.mutate(device.id)}
                                        disabled={revoke.isPending}
                                    >
                                        {tr("devices.revoke")}
                                    </button>
                                )}
                            </div>
                        </li>
                    );
                })}
            </ul>
        </section>
    );
}
