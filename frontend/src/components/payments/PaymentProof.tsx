import { useRef, useState, type ChangeEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { CameraIcon, DocumentArrowUpIcon, PaperClipIcon, XMarkIcon } from '@heroicons/react/20/solid'
import { Button } from '@/components/ui/Button'
import { apiUrl, ApiError, put, upload } from '@/lib/api'
import { cn } from '@/lib/cn'

export type Proof = { id: number; mime: string }

/** Photos are made smaller on the device (max 1600 px, JPEG) so they upload fast on a slow connection. */
async function shrink(file: File): Promise<File> {
  if (!file.type.startsWith('image/') || file.type === 'image/heic' || file.type === 'image/heif' || file.size < 400_000) return file
  try {
    const bitmap = await createImageBitmap(file)
    const scale = Math.min(1, 1600 / Math.max(bitmap.width, bitmap.height))
    const canvas = document.createElement('canvas')
    canvas.width = Math.round(bitmap.width * scale)
    canvas.height = Math.round(bitmap.height * scale)
    canvas.getContext('2d')?.drawImage(bitmap, 0, 0, canvas.width, canvas.height)
    const blob = await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.8))
    return blob && blob.size < file.size ? new File([blob], 'proof.jpg', { type: 'image/jpeg' }) : file
  } catch {
    return file // the browser cannot read it: send it as it is
  }
}

const proofUrl = (id: number) => apiUrl(`/payment-proofs/${id}`)

function useUploadProof() {
  return useMutation({
    mutationFn: async (file: File) => (await upload<{ data: Proof }>('/payment-proofs', 'file', await shrink(file))).data,
    meta: { toast: false },
  })
}

/** Two hidden file inputs: the camera (on phones and tablets) and "choose a file". */
function Pickers({ onFile, disabled }: { onFile: (f: File) => void; disabled?: boolean }) {
  const { t } = useTranslation()
  const camera = useRef<HTMLInputElement>(null)
  const file = useRef<HTMLInputElement>(null)
  const pick = (e: ChangeEvent<HTMLInputElement>) => {
    const f = e.target.files?.[0]
    e.target.value = ''
    if (f) onFile(f)
  }
  return (
    <div className="flex flex-wrap gap-2">
      <input ref={camera} type="file" accept="image/*" capture="environment" className="hidden" onChange={pick} aria-label={t('proof.takePhoto')} />
      <input ref={file} type="file" accept="image/*,application/pdf" className="hidden" onChange={pick} aria-label={t('proof.chooseFile')} />
      <Button type="button" variant="secondary" disabled={disabled} onClick={() => camera.current?.click()}>
        <CameraIcon aria-hidden className="size-4" />
        {t('proof.takePhoto')}
      </Button>
      <Button type="button" variant="secondary" disabled={disabled} onClick={() => file.current?.click()}>
        <DocumentArrowUpIcon aria-hidden className="size-4" />
        {t('proof.chooseFile')}
      </Button>
    </div>
  )
}

/** Optional proof on a payment form: take a photo or choose a file; shows a small preview. */
export function ProofField({ value, onChange }: { value: Proof | null; onChange: (p: Proof | null) => void }) {
  const { t } = useTranslation()
  const up = useUploadProof()
  const [preview, setPreview] = useState<string | null>(null)
  const error = up.error instanceof ApiError ? (up.error.field('file') ?? up.error.message) : null

  return (
    <div className="flex flex-col gap-1.5">
      <span className="text-[13px] font-medium">
        {t('proof.label')} <span className="font-normal text-muted">{t('proof.optional')}</span>
      </span>
      {value ? (
        <div className="flex items-center gap-3 rounded-lg bg-sunken p-2">
          {preview && value.mime.startsWith('image/') ? (
            <img src={preview} alt={t('proof.label')} className="size-12 rounded-md object-cover" />
          ) : (
            <PaperClipIcon aria-hidden className="size-5 text-icon" />
          )}
          <span className="flex-1 text-[13px]">{t('proof.added')}</span>
          <Button
            type="button"
            variant="ghost"
            aria-label={t('proof.remove')}
            onClick={() => {
              onChange(null)
              setPreview(null)
            }}
          >
            <XMarkIcon aria-hidden className="size-4" />
          </Button>
        </div>
      ) : (
        <Pickers
          disabled={up.isPending}
          onFile={(f) =>
            up.mutate(f, {
              onSuccess: (p) => {
                onChange(p)
                setPreview(f.type.startsWith('image/') && typeof URL.createObjectURL === 'function' ? URL.createObjectURL(f) : null)
              },
            })
          }
        />
      )}
      {up.isPending && <span className="text-xs text-muted">{t('proof.uploading')}</span>}
      {error && <span className="text-xs text-danger-text">{error}</span>}
    </div>
  )
}

/** On a payment line: open the proof, or add one later. */
export function ProofLink({ paymentId, proof, canAdd }: { paymentId: number; proof: Proof | null | undefined; canAdd: boolean }) {
  const { t } = useTranslation()
  const qc = useQueryClient()
  const up = useUploadProof()
  const attach = useMutation({
    mutationFn: (proofId: number) => put(`/payments/${paymentId}/proof`, { proof_id: proofId }),
    onSuccess: () => qc.invalidateQueries(),
    meta: { toast: 'proof.saved' },
  })
  const file = useRef<HTMLInputElement>(null)

  if (proof) {
    return (
      <a href={proofUrl(proof.id)} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1 text-xs font-medium text-accent-text hover:underline">
        <PaperClipIcon aria-hidden className="size-3.5" />
        {t('proof.view')}
      </a>
    )
  }
  if (!canAdd) return null
  return (
    <>
      <input
        ref={file}
        type="file"
        accept="image/*,application/pdf"
        className="hidden"
        aria-label={t('proof.add')}
        onChange={(e) => {
          const f = e.target.files?.[0]
          e.target.value = ''
          if (f) up.mutate(f, { onSuccess: (p) => attach.mutate(p.id) })
        }}
      />
      <button
        type="button"
        disabled={up.isPending || attach.isPending}
        onClick={() => file.current?.click()}
        className={cn('inline-flex items-center gap-1 text-xs font-medium text-muted hover:text-text disabled:opacity-60')}
      >
        <PaperClipIcon aria-hidden className="size-3.5" />
        {up.isPending || attach.isPending ? t('proof.uploading') : t('proof.add')}
      </button>
    </>
  )
}
