import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { IdCardIcon } from 'lucide-react'
import { Alert } from '@/components/ui/Alert'
import { Button } from '@/components/ui/Button'
import { PhotoPickers } from '@/components/ui/PhotoPickers'
import { apiUrl, ApiError, del, upload } from '@/lib/api'
import { shrink } from '@/lib/photos'

export type IdPhotoInfo = { mime: string; at: string | null } | null

/**
 * Photo of the guest's ID or passport (when the hotel turned it on in Booking rules).
 * The file is private: it is only shown to staff who may see guests.
 */
export function IdPhoto({ guestId, photo, canEdit, compact }: { guestId: number; photo: IdPhotoInfo; canEdit: boolean; compact?: boolean }) {
  const { t } = useTranslation()
  const qc = useQueryClient()
  const [version, setVersion] = useState(0)
  const done = () => {
    setVersion((v) => v + 1)
    void qc.invalidateQueries({ queryKey: ['guests'] })
    void qc.invalidateQueries({ queryKey: ['desk'] })
  }
  const save = useMutation({
    mutationFn: async (file: File) => upload(`/guests/${guestId}/id-photo`, 'file', await shrink(file)),
    onSuccess: done,
    meta: { toast: 'idPhoto.saved' },
  })
  const remove = useMutation({
    mutationFn: () => del(`/guests/${guestId}/id-photo`),
    onSuccess: () => {
      save.reset()
      done()
    },
    meta: { toast: 'idPhoto.removed' },
  })
  const error = save.error instanceof ApiError ? (save.error.field('file') ?? save.error.message) : null
  // Opened in a new tab with rel="noopener" (not "noreferrer"): the sign-in cookie only counts when the
  // browser says the request comes from the app.
  const src = `${apiUrl(`/guests/${guestId}/id-photo`)}?v=${version}`
  const has = !!photo || save.isSuccess

  return (
    <div className="flex flex-col gap-2">
      {!compact && <h2 className="text-sm font-semibold">{t('idPhoto.title')}</h2>}
      {has ? (
        <div className="flex flex-wrap items-center gap-3">
          {photo?.mime === 'application/pdf' ? (
            <a href={src} target="_blank" rel="noopener" className="flex size-20 items-center justify-center rounded-lg bg-sunken border">
              <IdCardIcon aria-hidden className="size-8 text-icon" />
            </a>
          ) : (
            <a href={src} target="_blank" rel="noopener">
              <img src={src} alt={t('idPhoto.title')} className="h-20 w-28 rounded-lg object-cover border" />
            </a>
          )}
          <span className="flex flex-col gap-1 text-xs text-muted">
            {t('idPhoto.saved')}
            {canEdit && (
              <span className="flex gap-1">
                <PhotoPickers disabled={save.isPending} onFile={(f) => save.mutate(f)} />
                <Button variant="ghost" disabled={remove.isPending} onClick={() => remove.mutate()}>
                  {t('idPhoto.remove')}
                </Button>
              </span>
            )}
          </span>
        </div>
      ) : canEdit ? (
        <PhotoPickers disabled={save.isPending} onFile={(f) => save.mutate(f)} />
      ) : (
        <p className="text-sm text-muted">{t('idPhoto.none')}</p>
      )}
      {save.isPending && <span className="text-xs text-muted">{t('proof.uploading')}</span>}
      {error && <Alert>{error}</Alert>}
    </div>
  )
}
