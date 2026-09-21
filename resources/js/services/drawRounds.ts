import api from './api';

export interface DrawDisplayPhoto {
    draw_url: string;
    photo_id: number;
    thumbnail_url: string;
}

export interface DrawCandidatePhoto {
    draw_url: string;
    effective_likes: number;
    like_bonus: number;
    lottery_status: 'approved' | 'featured';
    photo_id: number;
    thumbnail_url: string;
    weight: number;
}

export interface DrawCandidateUser {
    phone_suffix: string;
    photos: DrawCandidatePhoto[];
    user_id: number;
    weight: number;
}

export interface DrawCandidateSnapshot {
    total_weight: number;
    users: DrawCandidateUser[];
}

export interface PreparedDrawRound {
    candidate_snapshot: DrawCandidateSnapshot;
    completed_at: string | null;
    created_at: string;
    draw_count: number;
    id: number;
    round_number: number;
    settings_snapshot: {
        draw_count: number;
        exclude_previous_winners: boolean;
        selection_mode: 'user_then_photo';
    };
    status: 'prepared';
}

export interface DrawWinner {
    phone_suffix: string;
    position: number;
    photo: {
        draw_url: string;
        id: number;
        weight: number;
    };
    user_id: number;
}

export interface DrawResult {
    completed_at: string;
    round_id: number;
    round_number: number;
    status: 'completed';
    winners: DrawWinner[];
}

export interface DrawImagePreloadProgress {
    completed: number;
    failed: number;
    loaded: number;
    total: number;
}

export interface DrawImagePreloadResult extends DrawImagePreloadProgress {
    failedPhotoIds: number[];
}

const preloadConcurrency = 4;
const preloadTimeoutMilliseconds = 15_000;
const retainedDrawImages = new Map<string, Promise<HTMLImageElement>>();

export async function fetchDrawPhotos(): Promise<DrawDisplayPhoto[]> {
    const response = await api.get<{ photos: DrawDisplayPhoto[] }>('/draw-photos');

    return response.data.photos;
}

export async function prepareDrawRound(): Promise<PreparedDrawRound> {
    const response = await api.post<PreparedDrawRound>('/draw-rounds');

    return response.data;
}

export async function executeDrawRound(roundId: number): Promise<DrawResult> {
    const response = await api.post<DrawResult>(`/draw-rounds/${roundId}/draw`);

    return response.data;
}

export function flattenCandidatePhotos(round: PreparedDrawRound): DrawCandidatePhoto[] {
    return round.candidate_snapshot.users.flatMap((user) => user.photos);
}

export async function preloadCandidateDrawImages(
    photos: DrawCandidatePhoto[],
    reportProgress: (progress: DrawImagePreloadProgress) => void,
): Promise<DrawImagePreloadResult> {
    const uniquePhotos = Array.from(new Map(photos.map((photo) => [photo.photo_id, photo])).values());
    const progress: DrawImagePreloadProgress = {
        completed: 0,
        failed: 0,
        loaded: 0,
        total: uniquePhotos.length,
    };
    const failedPhotoIds: number[] = [];
    let nextIndex = 0;

    reportProgress({ ...progress });

    async function worker(): Promise<void> {
        while (nextIndex < uniquePhotos.length) {
            const photo = uniquePhotos[nextIndex];
            nextIndex += 1;

            try {
                await preloadDrawImage(photo.draw_url);
                progress.loaded += 1;
            } catch {
                progress.failed += 1;
                failedPhotoIds.push(photo.photo_id);
            } finally {
                progress.completed += 1;
                reportProgress({ ...progress });
            }
        }
    }

    await Promise.all(
        Array.from(
            { length: Math.min(preloadConcurrency, uniquePhotos.length) },
            () => worker(),
        ),
    );

    return {
        ...progress,
        failedPhotoIds,
    };
}

export function preloadDrawImage(url: string): Promise<HTMLImageElement> {
    const retainedImage = retainedDrawImages.get(url);

    if (retainedImage !== undefined) {
        return retainedImage;
    }

    const pendingImage = new Promise<HTMLImageElement>((resolve, reject) => {
        const image = new Image();
        const timeoutId = window.setTimeout(() => {
            image.src = '';
            reject(new Error('图片加载超时。'));
        }, preloadTimeoutMilliseconds);

        image.decoding = 'async';
        image.onload = () => {
            window.clearTimeout(timeoutId);

            void image.decode()
                .catch(() => undefined)
                .finally(() => resolve(image));
        };
        image.onerror = () => {
            window.clearTimeout(timeoutId);
            reject(new Error('图片加载失败。'));
        };
        image.src = url;
    }).catch((error: unknown) => {
        retainedDrawImages.delete(url);

        throw error;
    });

    retainedDrawImages.set(url, pendingImage);

    return pendingImage;
}
